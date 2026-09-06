<?php
namespace Api\app\controllers;

use Api\components\Log;
use Api\components\payment\Payment;
use Symfony\Component\HttpFoundation\Request;

class NotificationController {
    public function request(Request $request, string $provider)
    {
        Log::get(Log::PAYMENT)->info('Notification received', [
            'provider' => $provider,
            'headers' => $request->headers->all(),
            'body' => $request->getContent(),
        ]);

        $notificationResult = Payment::notification($provider, [
            'rawBody' => $request->getContent(),
            'receivedSignature' => $request->headers->get('x-nowpayments-sig'),
        ]);

//        $notificationResult = Payment::notification($provider, [
//            'rawBody' => '{"actually_paid":0.00778773999999999959331642429560815799050033092498779296875,"actually_paid_at_fiat":0,"fee":{"currency":"usdttrc20","depositFee":0.1129030000000000033555380696270731277763843536376953125,"serviceFee":0.1480229999999999879189971352388965897262096405029296875,"withdrawalFee":0},"invoice_id":5522024518,"order_description":"Balance refill old-mmorpg.com","order_id":"PAY-20260808-121725-59225d99","outcome_amount":14.6542300000000000892441676114685833454132080078125,"outcome_currency":"usdttrc20","parent_payment_id":null,"pay_address":"0x3133B7AFEB9a19b7664c9A3b7296bdadEFf62119","pay_amount":0.00778773999999999959331642429560815799050033092498779296875,"pay_currency":"eth","payin_extra_id":null,"payment_extra_ids":null,"payment_id":4726427731,"payment_status":"finished","price_amount":15,"price_currency":"usd","purchase_id":"4742303806"}',
//            'receivedSignature' => '4243cf35921cbca3b555615d28f076ca6a54f0916161943681711e7a37ba1e49fffb177038ab00c11f935751a440cdc8a3b57670c05795a1054ef586f1670b2d',
//        ]);

        return new \Symfony\Component\HttpFoundation\JsonResponse([
            'success' => $notificationResult
        ]);
    }
}