<?php

namespace DervisGroup\Pesa\Mpesa\Repositories;

use DervisGroup\Pesa\Mpesa\Database\Entities\MpesaBulkPaymentRequest;
use DervisGroup\Pesa\Mpesa\Database\Entities\MpesaBulkPaymentResponse;
use DervisGroup\Pesa\Mpesa\Database\Entities\MpesaC2bCallback;
use DervisGroup\Pesa\Mpesa\Database\Entities\MpesaStkCallback;
use DervisGroup\Pesa\Mpesa\Database\Entities\MpesaStkRequest;
use DervisGroup\Pesa\Mpesa\Events\B2cPaymentFailedEvent;
use DervisGroup\Pesa\Mpesa\Events\B2cPaymentSuccessEvent;
use DervisGroup\Pesa\Mpesa\Events\C2bConfirmationEvent;
use DervisGroup\Pesa\Mpesa\Events\StkPushPaymentFailedEvent;
use DervisGroup\Pesa\Mpesa\Events\StkPushPaymentSuccessEvent;
use Gahlawat\Slack\Facade\Slack;
use Illuminate\Support\Facades\Auth;

/**
 * Class Mpesa
 * @package DervisGroup\Pesa\Repositories
 */
class Mpesa
{
    /**
     * @param string $json
     * @return $this|array|\Illuminate\Database\Eloquent\Model
     */
    public function processStkPushCallback($json)
    {
        $object = json_decode($json);
        $data = $object->stkCallback;
        $real_data = [
            'MerchantRequestID' => $data->MerchantRequestID,
            'CheckoutRequestID' => $data->CheckoutRequestID,
            'ResultCode' => $data->ResultCode,
            'ResultDesc' => $data->ResultDesc,
        ];
        if ($data->ResultCode == 0) {
            $_payload = $data->CallbackMetadata->Item;
            foreach ($_payload as $callback) {
                $real_data[$callback->Name] = @$callback->Value;
            }
            $callback = MpesaStkCallback::create($real_data);
        } else {
            $callback = MpesaStkCallback::create($real_data);
        }
        $this->fireStkEvent($callback, get_object_vars($data));
        return $callback;
    }

    /**
     * @param $response
     * @param array $body
     * @return MpesaBulkPaymentRequest|\Illuminate\Database\Eloquent\Model
     */
    public function saveB2cRequest($response, $body = [])
    {
        return MpesaBulkPaymentRequest::create([
            'conversation_id' => $response->ConversationID,
            'originator_conversation_id' => $response->OriginatorConversationID,
            'amount' => $body['Amount'],
            'phone' => $body['PartyB'],
            'remarks' => $body['Remarks'],
            'CommandID' => $body['CommandID'],
            'user_id' => Auth::id(),
        ]);
    }

    /**
     * @param string $json
     * @return $this|\Illuminate\Database\Eloquent\Model
     */
    public function processConfirmation($json)
    {
        $data = json_decode($json, true);
        $callback = MpesaC2bCallback::create($data);
        event(new C2bConfirmationEvent($callback, $data));
        return $callback;
    }

    /**
     * @return MpesaBulkPaymentResponse|\Illuminate\Database\Eloquent\Model
     */
    private function handleB2cResult()
    {
        $data = json_decode(request('Result'), true);
        $common = [
            'ResultType', 'ResultCode', 'ResultDesc', 'OriginatorConversationID', 'ConversationID', 'TransactionID'
        ];
        $seek = ['OriginatorConversationID' => $data['OriginatorConversationID']];
        /** @var MpesaBulkPaymentResponse $response */
        $response = null;
        if ($data['ResultCode'] !== 0) {
            $response = MpesaBulkPaymentResponse::updateOrCreate($seek,
                array_only($data, $common));
            event(new B2cPaymentFailedEvent($response, $data));
            return $response;
        }
        $resultParameter = $data['ResultParameters'];
        $data['ResultParameters'] = json_encode($resultParameter);
        $response = MpesaBulkPaymentResponse::updateOrCreate($seek, array_except($data, ['ReferenceData']));
        event(new B2cPaymentSuccessEvent($response, $data));
        return $response;
    }

    /**
     * @param string|null $initiator
     * @return MpesaBulkPaymentResponse|void
     */
    public function handleResult($initiator = null)
    {
        if ($initiator === 'b2c') {
            return $this->handleB2cResult();
        }
        return;
    }

    /**
     * @param $title
     * @param bool $important
     */
    public function notification($title, $important = false): void
    {
        $slack = \config('dervisgroup.mpesa.notifications.slack_web_hook');
        if (!$important && empty($slack) && \config('dervisgroup.mpesa.notifications.only_important')) {
            return;
        }
        \config([
            'slack.incoming-webhook' => \config('dervisgroup.mpesa.notifications.slack_web_hook'),
            'slack.default_username' => 'MPESA',
            'slack.default_emoji' => ':mailbox_with_mail:',]);
        Slack::send($title);
        Slack::send('```' . json_encode(request()->all(), JSON_PRETTY_PRINT) . '```');
    }

    /**
     * @return array
     */
    public function queryStkStatus(): array
    {
        /** @var MpesaStkRequest[] $stk */
        $stk = MpesaStkRequest::whereDoesntHave('response')->get();
        $success = $errors = [];
        foreach ($stk as $item) {
            try {
                $status = mpesa_stk_status($item->id);
                if (isset($status->errorMessage)) {
                    $errors[$item->CheckoutRequestID] = $status->errorMessage;
                    continue;
                }
                $attributes = [
                    'MerchantRequestID' => $status->MerchantRequestID,
                    'CheckoutRequestID' => $status->CheckoutRequestID,
                    'ResultCode' => $status->ResultCode,
                    'ResultDesc' => $status->ResultDesc,
                    'Amount' => $item->amount,
                ];
                $errors[$item->CheckoutRequestID] = $status->ResultDesc;
                $callback = MpesaStkCallback::create($attributes);
                $this->fireStkEvent($callback, get_object_vars($status));
            } catch (\Exception $e) {
                $errors[$item->CheckoutRequestID] = $e->getMessage();
            }
        }
        return ['successful' => $success, 'errors' => $errors];
    }

    /**
     * @param MpesaStkCallback $stkCallback
     * @param array $response
     * @return MpesaStkCallback
     */
    private function fireStkEvent(MpesaStkCallback $stkCallback, $response): MpesaStkCallback
    {
        if ($stkCallback->ResultCode == 0) {
            event(new StkPushPaymentSuccessEvent($stkCallback, $response));
        } else {
            event(new StkPushPaymentFailedEvent($stkCallback, $response));
        }
        return $stkCallback;
    }
}
