<?php

namespace App\Shipping;

/**
 * One parcel to be priced: where from, where to, how heavy.
 *
 * Built per seller, not per cart. A five-seller checkout produces five of
 * these, because each shop ships from its own address.
 */
class ShipmentQuote
{
    public function __construct(
        public readonly string $originAreaId,
        public readonly string $destinationAreaId,

        /**
         * Actual weight in grams, summed across the seller's items
         * (weight_grams × quantity). Not yet rounded — see billableWeight().
         */
        public readonly int $weightGrams,

        /**
         * Goods value in rupiah, excluding shipping. Couriers use it to price
         * insurance, and some refuse high-value parcels on cheap services.
         */
        public readonly int $itemValue = 0,

        /**
         * Parcel dimensions in centimetres. Optional because Rapaku doesn't
         * collect them yet — when a seller eventually fills them in, couriers
         * price bulky-but-light parcels by volume instead of weight, and that
         * is usually the more expensive of the two.
         */
        public readonly ?int $lengthCm = null,
        public readonly ?int $widthCm = null,
        public readonly ?int $heightCm = null,

        /**
         * Restrict the quote to specific couriers. Empty means ask for all of
         * them, which is what checkout wants — a buyer should see the range.
         *
         * @var string[]
         */
        public readonly array $couriers = [],
    ) {}

    /**
     * What the courier will actually bill, in grams.
     *
     * Couriers charge per kilo and round up, so an 1100g parcel costs the same
     * as a 2000g one. Rounding here rather than at the call site is what makes
     * the cache key below collapse: every cart between 1001g and 2000g on the
     * same route shares one entry instead of a thousand.
     *
     * Volumetric weight is compared when dimensions exist. The divisor of 6000
     * is the common regular-service formula (cm³ ÷ 6000 = kg); cargo services
     * use 4000 and will under-quote slightly here. Acceptable for now, and the
     * kind of thing to revisit when cargo is actually offered.
     */
    public function billableWeight(): int
    {
        $weight = max($this->weightGrams, 1);

        if ($this->lengthCm && $this->widthCm && $this->heightCm) {
            $volumetric = (int) ceil(
                ($this->lengthCm * $this->widthCm * $this->heightCm) / 6000 * 1000
            );

            $weight = max($weight, $volumetric);
        }

        // Round up to the next whole kilogram.
        return (int) (ceil($weight / 1000) * 1000);
    }

    /**
     * Stable key for caching this quote's result.
     *
     * Only the fields that change the price go in. itemValue is deliberately
     * bucketed rather than exact: insurance is priced in bands, so quoting a
     * Rp 249.000 item and a Rp 251.000 item separately would double the cache
     * misses for no difference in the answer.
     *
     * The route and weight come first so keys sort together per route, which
     * makes them easy to eyeball in Redis when a quote looks wrong.
     */
    public function cacheKey(string $gateway): string
    {
        $valueBucket = (int) floor($this->itemValue / 100_000);

        $couriers = $this->couriers;
        sort($couriers);

        return implode(':', [
            'ship',
            $gateway,
            $this->originAreaId,
            $this->destinationAreaId,
            $this->billableWeight(),
            $valueBucket,
            $couriers ? md5(implode(',', $couriers)) : 'all',
        ]);
    }

    /**
     * Whether this is worth sending to a provider at all.
     *
     * A missing area id means a seller never set their shop origin, or a buyer
     * saved their address before the area picker existed. Catching it here
     * spends nothing; catching it at the provider spends a request from a
     * daily quota to be told what we already knew.
     */
    public function isQuotable(): bool
    {
        return $this->originAreaId !== ''
            && $this->destinationAreaId !== ''
            && $this->weightGrams > 0;
    }
}