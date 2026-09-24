<?php

/**
 * MetroVPS B2B — Billing hooks
 *
 *   DailyCronJob  : suspend VPS services whose invoice is overdue past the
 *                   WHMCS AutoSuspensionDays grace period (honors the
 *                   AutoSuspension automation setting)
 *   InvoicePaid   : unsuspend the service as soon as its invoice is paid
 *                   (honors the AutoUnsuspend automation setting)
 *
 * Both route through localAPI('ModuleSuspend' / 'ModuleUnsuspend') so WHMCS
 * core invokes MetroVPSB2B_SuspendAccount / MetroVPSB2B_UnsuspendAccount —
 * the single code path that calls the MetroVPS suspend/unsuspend APIs.
 */

use WHMCS\Database\Capsule as DB;

if (!defined('WHMCS')) {
    die('This file cannot be accessed directly');
}

add_hook('DailyCronJob', 1, function () {
    $autoSuspend = DB::table('tblconfiguration')->where('setting', 'AutoSuspension')->value('value');

    if ($autoSuspend !== 'on') {
        return;
    }

    $days   = (int) (DB::table('tblconfiguration')->where('setting', 'AutoSuspensionDays')->value('value') ?? 5);
    $days   = $days > 0 ? $days : 5;
    $cutoff = date('Y-m-d', strtotime('-' . $days . ' days'));

    $serviceIds = DB::table('tblhosting as h')
        ->join('tblproducts as p', 'h.packageid', '=', 'p.id')
        ->join('tblinvoiceitems as ii', 'ii.relid', '=', 'h.id')
        ->join('tblinvoices as i', 'ii.invoiceid', '=', 'i.id')
        ->where('p.servertype', 'MetroVPSB2B')
        ->where('h.domainstatus', 'Active')
        ->where('ii.type', 'Hosting')
        ->where('i.status', 'Unpaid')
        ->whereDate('i.duedate', '<=', $cutoff)
        ->distinct()
        ->pluck('h.id')
        ->take(50)
        ->toArray();

    foreach ($serviceIds as $serviceId) {
        $result = localAPI('ModuleSuspend', [
            'serviceid'      => (int) $serviceId,
            'suspendreason'  => 'Overdue on Payment',
        ]);

        logModuleCall(
            'MetroVPSB2B',
            'billing:dueSuspend',
            ['service_id' => (int) $serviceId],
            $result,
            ($result['result'] ?? '') === 'success' ? 'success' : 'error'
        );
    }

    if (!empty($serviceIds)) {
        logModuleCall('MetroVPSB2B', 'billing:dueSuspend:batch', ['count' => count($serviceIds)], 'Processed overdue suspensions.', '');
    }
});

add_hook('InvoicePaid', 1, function ($vars) {
    $autoUnsuspend = DB::table('tblconfiguration')->where('setting', 'AutoUnsuspend')->value('value');

    if ($autoUnsuspend !== 'on') {
        return;
    }

    $invoiceId = (int) ($vars['invoiceid'] ?? 0);

    if ($invoiceId <= 0) {
        return;
    }

    $serviceIds = DB::table('tblinvoiceitems')
        ->where('invoiceid', $invoiceId)
        ->where('type', 'Hosting')
        ->pluck('relid')
        ->toArray();

    foreach ($serviceIds as $serviceId) {
        $serviceId = (int) $serviceId;

        $hosting = DB::table('tblhosting')->where('id', $serviceId)->first(['packageid', 'domainstatus']);

        if (!$hosting || $hosting->domainstatus !== 'Suspended') {
            continue;
        }

        $isMetro = DB::table('tblproducts')
            ->where('id', (int) $hosting->packageid)
            ->where('servertype', 'MetroVPSB2B')
            ->exists();

        if (!$isMetro) {
            continue;
        }

        $result = localAPI('ModuleUnsuspend', ['serviceid' => $serviceId]);

        logModuleCall(
            'MetroVPSB2B',
            'billing:paidUnsuspend',
            ['service_id' => $serviceId, 'invoice_id' => $invoiceId],
            $result,
            ($result['result'] ?? '') === 'success' ? 'success' : 'error'
        );
    }
});