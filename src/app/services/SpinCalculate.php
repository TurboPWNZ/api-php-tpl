<?php

namespace Api\app\services;

/**
 * Расчёт результата спина.
 *
 * Клиент делает ставку bet (ставка за линию) на lines линий.
 * Общая ставка = bet * lines.
 *
 * Payout-таблица задана как множитель от ставки за линию (bet):
 * при bet 1/5/10/20 множитель 5 даёт 5/25/50/100 т.д.
 *
 * Шанс выпадения каждой комбинации обратно пропорционален её множителю:
 * при bet 1 комбинация с выигрышем 5x выпадает с шансом 1 к 5,
 * комбинация с выигрышем 20x — с шансом 1 к 20 и т.д.
 * Если линии больше 1, шанс каждой комбинации умножается на количество
 * линий (при lines = 50 у каждой комбинации шанс 1 к 1 — гарантированный
 * выигрыш одной из них).
 */
class SpinCalculate
{
    const CHANCE_COEFFICIENT = 3;

    /**
     * Коэффициент регулирования шанса для самой частой (низкой) комбинации —
     * той, у которой наименьший множитель в PAYOUT_MULTIPLIERS.
     * Чем меньше значение, тем выше шанс выпадения этой комбинации.
     */
    const CHANCE_COEFFICIENT_LOW = 2;

    /**
     * Комбинации выигрыша: имя линии => множитель ставки за линию (bet).
     *
     *   bet 1 / 5 / 10 / 20
     *   5x  -> 5   / 25  / 50   / 100
     *   10x -> 10  / 50  / 100  / 200
     *   15x -> 15  / 75  / 150  / 300
     *   20x -> 20  / 100 / 200  / 400
     *   25x -> 25  / 125 / 250  / 500
     *   50x -> 50  / 250 / 500  / 1000 (JACKPOT)
     *
     * bet 1: 5 / 10 / 15 / 20 / 25 / 50
     */
    public const PAYOUT_MULTIPLIERS = [
        'cherry'     => 5,   // 🍒 Cherry ×3
//        'lemon'      => 5,   // 🍋 Lemon ×3
        'orange'     => 10,  // 🍊 Orange ×3
//        'plum'       => 10,  // 🍑 Plum ×3
        'grapes'     => 15,  // 🍇 Grapes ×3
        'watermelon' => 20,  // 🍉 Watermelon ×3
        'seven'      => 25,  // 7️⃣ Seven ×3
//        'joker'      => 25,  // 🃏 Joker ×3
        'crown'      => 50,  // 👑 Crown ×3 (JACKPOT)
    ];

    /**
     * Случайно выбрать комбинацию результата спина.
     *
     * Алгоритм ровно такой:
     *
     * Берём линию.
     * Перебираем комбинации по очереди.
     * Для каждой комбинации делаем отдельный бросок с шансом 1 / multiplier.
     * Угадала — сразу возвращаем $bet * $multiplier.
     * Не угадала — следующая комбинация.
     * Все комбинации проиграли — переходим к следующей линии.
     * Все линии проиграли — 0.
 *
     * @param float $bet   Ставка за линию
     * @param int   $lines Количество линий (>= 1)
     *
     * @return array {
     *   @type float $win      Сумма выигрыша (0 если проигрыш),
     *   @type string|null $combination Выигрышная комбинация или null
     * }
     */
    public static function calculate(float $bet, int $lines): array
    {
        if ($lines < 1) {
            $lines = 1;
        }

        $lowKey = array_keys(self::PAYOUT_MULTIPLIERS, min(self::PAYOUT_MULTIPLIERS))[0];

        for ($line = 0; $line < $lines; $line++) {
            foreach (self::PAYOUT_MULTIPLIERS as $key => $multiplier) {
                $coefficient = $key === $lowKey ? self::CHANCE_COEFFICIENT_LOW : self::CHANCE_COEFFICIENT;
                $chance = 1 / ($multiplier * $coefficient);
                $roll = random_int(1, 100) / 100;
                if ($roll < $chance) {
                    return [
                        'win' => round($bet * $multiplier, 2),
                        'combination' => $key,
                    ];
                }
            }
        }

        return [
            'win' => 0.0,
            'combination' => null,
        ];
    }
}
