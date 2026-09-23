<?php

namespace Modules\Bpl\Support;

use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\DB;

/**
 * Builds the data a reel label needs and renders it.
 *
 * The two streams read from different masters — a hardroll's grammage, ply,
 * width and slice live on `bpl_products_hardroll`, a softroll's grammage and
 * diameter on `bpl_products_softroll` — so each has its own query, and both
 * flatten into one shape the label Blade can print without knowing which is
 * which.
 */
class RollLabel
{
    public static function hardroll(int $id): View
    {
        $row = DB::connection('bpl')->table('bpl_production as r')
            ->leftJoin('bpl_products_hardroll as p', 'r.product_id', '=', 'p.id')
            ->leftJoin('bpl_customers as c', 'r.customer_id', '=', 'c.id')
            // A grade type maps to a grade by `type`, the way the legacy label
            // joined it — bpl_production carries no grade id of its own.
            ->leftJoin('bpl_grades as g', 'p.gradetype', '=', 'g.type')
            ->where('r.id', $id)
            ->whereNull('r.deleted_at')
            ->first([
                'r.barcode', 'r.hardrollnumber', 'r.weight', 'r.net_weight', 'r.joints',
                'r.brightness', 'r.papermachine', 'r.dateofmanufacture', 'r.comments',
                'p.productname', 'p.gradetype', 'p.gsm', 'p.ply', 'p.width', 'p.diameter', 'p.slice',
                'c.customerlabel', 'c.customeraddress', 'g.gradename',
            ]);

        abort_if(! $row, 404);

        return view('bpl::print.roll-label', [
            'stream' => 'hardroll',
            'roll' => [
                'barcode' => $row->barcode,
                'rollnumber' => $row->hardrollnumber,
                'productname' => $row->productname,
                'gradetype' => $row->gradetype,
                'gradename' => $row->gradename,
                'gsm' => $row->gsm,
                'ply' => $row->ply,
                'width' => $row->width,
                'diameter' => $row->diameter,
                'slice' => (int) ($row->slice ?: 1),
                'brightness' => $row->brightness,
                'weight' => (float) $row->weight,
                'net_weight' => (float) $row->net_weight,
                'joints' => $row->joints,
                'papermachine' => $row->papermachine,
                'dateofmanufacture' => $row->dateofmanufacture,
                'customerlabel' => $row->customerlabel,
                'customeraddress' => $row->customeraddress,
                'comments' => array_values(array_filter(explode(',', (string) $row->comments))),
            ],
        ]);
    }

    public static function softroll(int $id): View
    {
        $row = DB::connection('bpl')->table('bpl_softroll_production as r')
            ->leftJoin('bpl_products_softroll as p', 'r.product_id', '=', 'p.id')
            ->leftJoin('bpl_grades as g', 'r.grade_id', '=', 'g.id')
            ->where('r.id', $id)
            ->whereNull('r.deleted_at')
            ->first([
                'r.barcode', 'r.softrollnumber', 'r.weight', 'r.brightness', 'r.papermachine',
                'r.dateofmanufacture', 'r.grammage', 'r.diameter',
                'p.productname', 'g.type as gradetype', 'g.gradename',
            ]);

        abort_if(! $row, 404);

        return view('bpl::print.roll-label', [
            'stream' => 'softroll',
            'roll' => [
                'barcode' => $row->barcode,
                'rollnumber' => $row->softrollnumber,
                // Rolls made before the product split have no product row; the
                // roll's own grade and dimensions still describe it.
                'productname' => $row->productname ?: trim($row->gradetype . ' ' . $row->grammage . 'gsm ' . $row->diameter . 'd'),
                'gradetype' => $row->gradetype,
                'gradename' => $row->gradename,
                'gsm' => $row->grammage,
                'diameter' => $row->diameter,
                'slice' => 1,
                'brightness' => $row->brightness,
                'weight' => (float) $row->weight,
                'net_weight' => 0,
                'joints' => 0,
                // The softroll table stores the machine number, not the name.
                'papermachine' => 'PM' . $row->papermachine,
                'dateofmanufacture' => $row->dateofmanufacture,
                'comments' => [],
            ],
        ]);
    }
}
