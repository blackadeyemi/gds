<?php

/*
| What a warehouse stores.
|
| This is NOT a cosmetic tag. The module decides which product master a
| warehouse's stock refers to and which stock table holds it, because a
| `productid` means a different thing in each:
|
|   raw-materials   -> bil.rawmaterials_products   -> raw_materials_warehouse_stock
|   finished-goods  -> bil.products                -> finished_goods_warehouse_stock
|   jumbo-rolls     -> bpl.bpl_products            -> (not built yet)
|   waste-paper     -> (not built yet)             -> (not built yet)
|
| It lives in config rather than a lookup table for exactly that reason: adding
| a module needs its stock table, its product picker and its screens, so it is a
| code change. A row added in Settings would look like it worked and then
| silently have nowhere to put stock.
|
| A warehouse holds ONE module. A building that genuinely stores two kinds is
| two warehouses — they have separate stock, separate gates and separate staff
| anyway.
*/

return [

    'modules' => [
        'raw-materials' => 'Raw Materials',
        'finished-goods' => 'Finished Goods',
        'jumbo-rolls' => 'Jumbo Rolls',
        'waste-paper' => 'Waste Paper',
    ],

    /*
    | Modules that have a stock table and screens behind them today. A warehouse
    | can be created against any module above, but only these can actually
    | receive — the rest are placeholders for work not done yet, and the
    | receiving screens say so rather than failing silently.
    */
    'implemented' => [
        'raw-materials',
        'finished-goods',
    ],

    /*
    | The day gds took over finished-goods movements.
    |
    | Receipts imported from the legacy `store_entrance` are flagged historic and
    | never count toward stock; loadings have no such flag, so they are excluded
    | by this date instead. Both boundaries must agree, or stock would be charged
    | for dispatches of goods it never counted receiving.
    |
    | Set this to the day the finished-goods warehouses actually went live.
    */
    'finished_goods_cutover' => env('FG_STOCK_CUTOVER', '2026-08-12'),

    /*
    | Which way goods move through a gate. `both` exists because a single
    | elevator or roller door is often used in either direction.
    */
    'directions' => [
        'in' => 'Entrance',
        'out' => 'Exit',
        'both' => 'Entrance & Exit',
    ],
];
