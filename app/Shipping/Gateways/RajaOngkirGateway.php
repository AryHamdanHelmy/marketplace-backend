<?php

namespace App\Shipping\Gateways;

use App\Shipping\ShipmentQuote;
use App\Shipping\ShippingArea;
use App\Shipping\ShippingGateway;
use App\Shipping\ShippingRate;
use App\Shipping\TrackingResult;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * RajaOngkir (Komerce) driver.
 *
 * Mapped against live responses rather than the docs. Three things about this
 * API shape the code below:
 *
 *   - Auth is a plain `key` header, not a bearer token.
 *   - The cost endpoint wants form-encoded input and a colon-joined courier
 *     list ("jne:jnt"), while the destination endpoint is a normal GET.
 *   - Results come back unsorted, and freight services are mixed in with
 *     parcel ones. Both are dealt with here so the rest of the app can trust
 *     that rates arrive cheapest first.
 */
class RajaOngkirGateway implements ShippingGateway
{
    /**
     * Trucking/cargo services quoted even for a 1kg parcel.
     *
     * Asking for a single kilo returns JNE Trucking at Rp 300.000–550.000
     * alongside a Rp 10.000 city courier. Those are minimum-charge freight
     * tariffs for pallets, and showing them to someone buying a t-shirt makes
     * the checkout page look broken.
     *
     * Matched as a prefix because the service codes vary: JTR, JTR<130,
     * JTR>130, JTR>200.
     */
    private const FREIGHT_PREFIXES = ['JTR', 'CTCSPS'];

    public function name(): string
    {
        return 'rajaongkir';
    }

    public function searchAreas(string $query, int $limit = 10): array
    {
        $response = $this->request()->get('/destination/domestic-destination', [
            'search' => $query,
            'limit'  => $limit,
        ]);

        if (!$this->succeeded($response)) {
            return [];
        }

        return array_map(function (array $row) {
            return new ShippingArea(
                id: (string) $row['id'],
                label: $row['label'] ?? '',

                // The label packs five parts — kelurahan, kecamatan, kota,
                // provinsi, kode pos — so it is never split here. These
                // fields already carry them, correctly named.
                district: $row['district_name'] ?? null,
                city: $row['city_name'] ?? null,
                province: $row['province_name'] ?? null,
                postalCode: $row['zip_code'] ?? null,
            );
        }, $response->json('data') ?? []);
    }

    public function quote(ShipmentQuote $shipment): array
    {
        $couriers = $shipment->couriers ?: config('shipping.couriers', ['jne', 'jnt']);

        $response = $this->request()->asForm()->post('/calculate/domestic-cost', [
            'origin'      => $shipment->originAreaId,
            'destination' => $shipment->destinationAreaId,

            // Grams, already rounded up to the next billable kilo.
            'weight'      => $shipment->billableWeight(),

            // Colon-separated, not comma. A comma returns an empty list with
            // a 200, which reads exactly like an unserved route.
            'courier'     => implode(':', $couriers),
        ]);

        if (!$this->succeeded($response)) {
            return [];
        }

        $rates = [];

        foreach ($response->json('data') ?? [] as $row) {
            $service = (string) ($row['service'] ?? '');

            if ($this->isFreight($service)) {
                continue;
            }

            $cost = (int) ($row['cost'] ?? 0);

            // A zero price is not a free delivery, it is a courier that
            // couldn't quote this route. Letting it through would offer the
            // buyer a shipping option that no one has agreed to carry.
            if ($cost <= 0) {
                continue;
            }

            $rates[] = new ShippingRate(
                courierCode: (string) ($row['code'] ?? ''),
                courierName: $this->shortName((string) ($row['name'] ?? '')),
                serviceCode: $service,
                serviceName: (string) ($row['description'] ?? $service),
                cost: $cost,
                etd: $this->etd($row['etd'] ?? null),
            );
        }

        // The API returns them in no particular order. Sorting here is what
        // makes "cheapest is preselected" true on the checkout page.
        usort($rates, fn (ShippingRate $a, ShippingRate $b) => $a->cost <=> $b->cost);

        return $rates;
    }

    public function track(string $courierCode, string $trackingNumber): ?TrackingResult
    {
        $response = $this->request()->asForm()->post('/track/waybill', [
            'awb'     => $trackingNumber,
            'courier' => $courierCode,
        ]);

        if (!$this->succeeded($response)) {
            return null;
        }

        $data = $response->json('data') ?? [];

        $delivery = $data['delivery_status'] ?? [];
        $details  = $data['details'] ?? [];
        $manifest = $data['manifest'] ?? [];

        $raw = strtoupper((string) ($delivery['status'] ?? $data['status'] ?? ''));

        return new TrackingResult(
            trackingNumber: $details['waybill_number'] ?? $trackingNumber,
            courierCode: $courierCode,
            status: $this->mapStatus($raw, $manifest),
            statusText: $delivery['status'] ?? null,
            lastUpdatedAt: $this->parseDate(
                $delivery['pod_date'] ?? null,
                $delivery['pod_time'] ?? null
            ),
            receivedBy: $delivery['pod_receiver'] ?? null,

            // Newest first. Couriers hand these back oldest first, and the
            // order page shows the latest scan at the top.
            history: array_reverse(array_map(fn (array $m) => [
                'date'        => trim(($m['manifest_date'] ?? '') . ' ' . ($m['manifest_time'] ?? '')),
                'description' => $m['manifest_description'] ?? '',
                'location'    => $m['city_name'] ?? null,
            ], $manifest)),
        );
    }

    /**
     * Translate the courier's wording into Rapaku's five states.
     *
     * The vocabulary here is not stable across couriers, so anything
     * unrecognised falls back to in_transit when there are scans and pending
     * when there aren't — never to a final state, because a final state stops
     * the polling job from ever asking again.
     */
    private function mapStatus(string $raw, array $manifest): string
    {
        if (str_contains($raw, 'DELIVERED') || str_contains($raw, 'TERKIRIM')) {
            return TrackingResult::DELIVERED;
        }

        if (
            str_contains($raw, 'RETURN')
            || str_contains($raw, 'RETUR')
            || str_contains($raw, 'LOST')
            || str_contains($raw, 'PROBLEM')
        ) {
            return TrackingResult::PROBLEM;
        }

        if (str_contains($raw, 'ON PROCESS') || str_contains($raw, 'TRANSIT')) {
            return TrackingResult::IN_TRANSIT;
        }

        return $manifest ? TrackingResult::IN_TRANSIT : TrackingResult::PENDING;
    }

    /**
     * Trim the courier's legal name down to what fits a checkout row.
     *
     * "Jalur Nugraha Ekakurir (JNE)" is accurate and unreadable at 14px on a
     * phone. The bracketed short name is what everyone actually calls them.
     */
    private function shortName(string $name): string
    {
        if (preg_match('/\(([^)]+)\)/', $name, $matches)) {
            return $matches[1];
        }

        return $name;
    }

    /**
     * Normalise the delivery estimate.
     *
     * Values seen live: "", "0 day", "1 day", "3 day". An empty string means
     * the courier didn't say, and "0 day" means same day — neither should be
     * printed raw next to the word "day" by the frontend.
     */
    private function etd(?string $etd): ?string
    {
        $etd = trim((string) $etd);

        if ($etd === '') {
            return null;
        }

        if (str_starts_with($etd, '0')) {
            return 'Same day';
        }

        // "1 day" / "3 day" — pluralise so the page can print it verbatim.
        if (preg_match('/^(\d+)\s*day/i', $etd, $m)) {
            return $m[1] === '1' ? '1 day' : $m[1] . ' days';
        }

        return $etd;
    }

    private function isFreight(string $service): bool
    {
        foreach (self::FREIGHT_PREFIXES as $prefix) {
            if (str_starts_with(strtoupper($service), $prefix)) {
                return true;
            }
        }

        return false;
    }

    private function parseDate(?string $date, ?string $time): ?Carbon
    {
        if (!$date) {
            return null;
        }

        try {
            return Carbon::parse(trim($date . ' ' . $time), 'Asia/Jakarta');
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * True only when the call went through AND the API said success.
     *
     * A quota ceiling comes back as a non-200 body with a 200-ish envelope on
     * some plans, so both layers are checked. Failures are logged and
     * swallowed — the contract says a driver never throws for these.
     */
    private function succeeded($response): bool
    {
        if (!$response->successful()) {
            Log::warning('RajaOngkir request failed', [
                'status' => $response->status(),
                'body'   => $response->body(),
            ]);

            return false;
        }

        $code = (int) $response->json('meta.code', 0);

        if ($code !== 200) {
            Log::warning('RajaOngkir returned an error', [
                'code'    => $code,
                'message' => $response->json('meta.message'),
            ]);

            return false;
        }

        return true;
    }

    private function request()
    {
        return Http::baseUrl(rtrim(config('shipping.rajaongkir.base_url'), '/'))
            ->withHeaders(['key' => config('shipping.rajaongkir.api_key')])
            ->acceptJson()
            ->timeout(15)
            ->retry(2, 300, throw: false);
    }
}