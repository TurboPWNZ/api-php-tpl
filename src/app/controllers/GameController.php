<?php

namespace Api\app\controllers;

use Api\app\services\SpinCalculate;
use Api\components\Log;
use Api\Configurator;
use Api\db\DatabaseManager;
use Api\db\TelegramAccount;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\JsonResponse;

class GameController
{
    public function spin(Request $request): JsonResponse
    {
        $user = $request->attributes->get('user');
        $userId = (int)($user['user_id'] ?? 0);

        if (!$userId) {
            return new JsonResponse([
                'success' => false,
                'errors' => ['Invalid user'],
            ], 401);
        }

        $data = $request->toArray();

        $bet = (float)($data['bet'] ?? 0);
        $lines = (int)($data['lines'] ?? 0);

        $config = Configurator::getConfig();
        $minBet = (float)($config['params']['gameMinBet'] ?? 10);
        $maxBet = (float)($config['params']['gameMaxBet'] ?? 200);
        $maxLines = (int)($config['params']['gameMaxLines'] ?? 20);

        // ─── Валидация ставки ────────────────────────────────────────────────
        // Каждый отказ пишем в spin.log — раньше отклонённые спины (неверная
        // ставка/линии, нехватка баланса) не оставляли вообще никакого следа
        // в логах, из-за чего периодические "server did not respond" на
        // клиенте нечем было объяснить.
        if ($bet <= 0) {
            Log::get(Log::SPIN)->warning('Spin rejected: bet not positive', [
                'user_id' => $userId, 'bet' => $bet, 'lines' => $lines,
            ]);
            return new JsonResponse([
                'success' => false,
                'errors' => ['bet must be positive'],
            ], 400);
        }

        if ($bet < $minBet || $bet > $maxBet) {
            Log::get(Log::SPIN)->warning('Spin rejected: bet out of range', [
                'user_id' => $userId, 'bet' => $bet, 'lines' => $lines,
                'minBet' => $minBet, 'maxBet' => $maxBet,
            ]);
            return new JsonResponse([
                'success' => false,
                'errors' => ['bet must be between ' . $minBet . ' and ' . $maxBet],
            ], 400);
        }

        if ($lines < 1 || $lines > $maxLines) {
            Log::get(Log::SPIN)->warning('Spin rejected: lines out of range', [
                'user_id' => $userId, 'bet' => $bet, 'lines' => $lines, 'maxLines' => $maxLines,
            ]);
            return new JsonResponse([
                'success' => false,
                'errors' => ['lines must be between 1 and ' . $maxLines],
            ], 400);
        }

        $stake = round($bet * $lines, 2);

        // ─── Аккаунт и баланс ────────────────────────────────────────────────
        $account = TelegramAccount::findByTelegramId($userId);

        if ($account === null) {
            Log::get(Log::SPIN)->warning('Spin rejected: account not found', [
                'user_id' => $userId, 'bet' => $bet, 'lines' => $lines,
            ]);
            return new JsonResponse([
                'success' => false,
                'errors' => ['Account not found. Please re-login.'],
            ], 404);
        }

        // ─── Расчёт и списание ───────────────────────────────────────────────
        $result = SpinCalculate::calculate($bet, $lines);
        $win = $result['win'];
        $delta = $win - $stake; // win - stake (может быть отрицательным)

        // Атомарно: списываем ставку, при выигрыше сразу возвращаем его.
        // Условие balance >= stake исключает списание при нехватке баланса,
        // даже если два спина выполняются одновременно.
        // increment() возвращает количество затронутых строк (0 | 1).
        $updated = DatabaseManager::connection()
            ->table('telegram_account')
            ->where('id', $account->id)
            ->where('balance', '>=', $stake)
            ->increment('balance', $delta);

        if ($updated === 0) {
            Log::get(Log::SPIN)->warning('Spin rejected: insufficient balance', [
                'user_id' => $userId, 'stake' => $stake,
                'balance' => $account->balance, 'win' => $win, 'combination' => $result['combination'],
            ]);
            return new JsonResponse([
                'success' => false,
                'errors' => ['Insufficient balance'],
            ], 400);
        }

        $account->refresh();

        Log::get(Log::SPIN)->info('Spin result', [
            'user_id' => $userId,
            'stake' => $stake,
            'win' => $win,
            'combination' => $result['combination'],
            'delta' => $delta,
            'new_balance' => $account->balance,
        ]);

        return new JsonResponse([
            'success' => true,
            'data' => [
                'win' => $win,
            ],
        ]);
    }
}
