<?php
declare(strict_types=1);

namespace App\Services\Payouts;

use App\Support\Money;

/**
 * Pure tax arithmetic for one payout item (integer paise; no I/O).
 *
 * GST (only when the payee has a GSTIN; otherwise none):
 *   none       taxable = gross, GST 0
 *   exclusive  taxable = gross, GST = taxable × rate (added on top of the commission)
 *   inclusive  gross already includes GST: taxable = gross ÷ (1 + rate), GST = gross − taxable
 *
 * TDS under section 194H on the taxable value (TDS is not deducted on the GST component):
 *   FY cumulative per PAN (non-void ledger rows before this item) = prior
 *   prior + taxable ≤ threshold  → no TDS yet
 *   prior > threshold            → TDS = taxable × rate
 *   this item crosses it         → TDS on the whole FY cumulative, less TDS already deducted:
 *                                  (prior + taxable) × rate − prior TDS
 *
 * net = taxable + GST − TDS + adjustments (adjustments are signed corrections to the amount payable).
 */
final class TaxCalculator
{
    /**
     * @param array{tds_rate_bp:int, tds_threshold:int, gst_mode:string, gst_rate_bp:int} $settings
     * @return array{taxable:int, gst_mode:string, gst_rate_bp:int, gst:int, tds_rate_bp:int, tds:int, tds_note:string,
     *               adjustments:int, net:int, fy_cumulative:int}
     */
    public static function compute(int $gross, bool $hasGstin, array $settings, int $priorTaxable, int $priorTds, int $adjustments = 0): array
    {
        $mode  = $hasGstin ? $settings['gst_mode'] : 'none';
        $gstBp = $mode === 'none' ? 0 : $settings['gst_rate_bp'];

        if ($mode === 'inclusive') {
            $taxable = Money::exclusiveOf($gross, $gstBp);
            $gst     = $gross - $taxable;
        } elseif ($mode === 'exclusive') {
            $taxable = $gross;
            $gst     = Money::percentOf($taxable, $gstBp);
        } else {
            $taxable = $gross;
            $gst     = 0;
        }

        $rate      = $settings['tds_rate_bp'];
        $threshold = $settings['tds_threshold'];
        $cumAfter  = $priorTaxable + $taxable;

        if ($rate <= 0) {
            $tds  = 0;
            $note = 'TDS rate is 0 in Settings.';
        } elseif ($cumAfter <= $threshold) {
            $tds  = 0;
            $note = sprintf('Below the FY threshold: cumulative ₹%s of ₹%s.', Money::format($cumAfter), Money::format($threshold));
        } elseif ($priorTaxable > $threshold) {
            $tds  = Money::percentOf($taxable, $rate);
            $note = sprintf('FY threshold already crossed (cumulative ₹%s).', Money::format($cumAfter));
        } else {
            $tds  = max(0, Money::percentOf($cumAfter, $rate) - $priorTds);
            $note = $priorTaxable > 0
                ? sprintf('Crosses the FY threshold: TDS on the FY cumulative ₹%s, including ₹%s paid earlier this FY without TDS.', Money::format($cumAfter), Money::format($priorTaxable))
                : sprintf('Exceeds the FY threshold of ₹%s.', Money::format($threshold));
        }

        return [
            'taxable'       => $taxable,
            'gst_mode'      => $mode,
            'gst_rate_bp'   => $gstBp,
            'gst'           => $gst,
            'tds_rate_bp'   => $rate,
            'tds'           => $tds,
            'tds_note'      => $note,
            'adjustments'   => $adjustments,
            'net'           => $taxable + $gst - $tds + $adjustments,
            'fy_cumulative' => $cumAfter,
        ];
    }
}
