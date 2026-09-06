<?php

namespace Api\app\services;

/**
 * Расчёт результата спина.
 *
 * Клиент делает ставку bet (ставка за линию) на lines линий.
 * Общая ставка = bet * lines.
 *
 * Payout-таблица задана как множитель от ставки за линию (bet):
 * при bet 10/50/100/200 множитель 5 даёт 50/250/500/1000 т.д.
 */
class SpinCalculate
{
    /**
     * Комбинации выигрыша: имя линии => множитель ставки за линию (bet).
     *
     *   bet 10 / 50 / 100 / 200
     *   5x  -> 50  / 250 / 500 / 1000
     *   10x -> 100 / 500 / 1000 / 2000
     *   15x -> 150 / 750 / 1500 / 3000
     *   20x -> 200 / 1000 / 2000 / 4000
     *   25x -> 250 / 1250 / 2500 / 5000
     *   50x -> 500 / 2500 / 5000 / 10000 (JACKPOT)
     *
     * bet 10: 50 / 100 / 150 / 200 / 250 / 500
     */
    public const PAYOUT_MULTIPLIERS = [
        'cherry'     => 5,   // 🍒 Cherry ×3
        'lemon'      => 5,   // 🍋 Lemon ×3
        'orange'     => 10,  // 🍊 Orange ×3
        'plum'       => 10,  // 🍑 Plum ×3
        'grapes'     => 15,  // 🍇 Grapes ×3
        'watermelon' => 20,  // 🍉 Watermelon ×3
        'seven'      => 25,  // 7️⃣ Seven ×3
        'joker'      => 25,  // 🃏 Joker ×3
        'crown'      => 50,  // 👑 Crown ×3 (JACKPOT)
    ];

    /**
     * Случайно выбрать одну из возможных сум выигрыша.
     *
     * @param float $bet   Ставка за линию
     * @param int   $lines Количество линий (не используется в текущей логике)
     *
     * @return array {
     *   @type float $win      Сумма выигрыша (0 если проигрыш),
     *   @type string|null $combination Выигрышная комбинация или null
     * }
     */
    public static function calculate(float $bet, int $lines): array
    {
        $multipliers = array_values(self::PAYOUT_MULTIPLIERS);
        $keys = array_keys(self::PAYOUT_MULTIPLIERS);

        // На текущем этапе игрок рандомно выигрывает одну из сум из таблицы
        $index = random_int(0, count($multipliers) - 1);
        $win = round($bet * $multipliers[$index], 2);

        return [
            'win' => $win,
            'combination' => $keys[$index],
        ];
    }
}
