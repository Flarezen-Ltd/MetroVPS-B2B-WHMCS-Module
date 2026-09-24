<?php

/**
 * MetroVPS B2B — Billing hooks
 *
 *   DailyCronJob  : suspend VPS services whose invoice is overdue past the
 *                   WHMCS AutoSuspensionDays grace period (honors the
 *                   AutoSuspension automation setting)
 *   InvoicePaid   : unsuspend the service as soon as its invoice is paid
 *                   (honors the AutoUnsuspend automation setting)
 *   InvoicePaid   : renew the service at MetroVPS once its renewal invoice is
 *                   paid. Renewal calls are fire-and-forget — a failed upstream
 *                   API call never affects the WHMCS service.
 *
 * Suspend/unsuspend route through localAPI('ModuleSuspend' / 'ModuleUnsuspend')
 * so WHMCS core invokes MetroVPSB2B_SuspendAccount / MetroVPSB2B_UnsuspendAccount —
 * the single code path that calls the MetroVPS suspend/unsuspend APIs. Renewal
 * routes through Module::renewService(), which never throws.
 */

use WHMCS\Database\Capsule as DB;

if (!defined('WHMCS')) {
    die('This file cannot be accessed directly');
}

require_once __DIR__ . '/../../modules/servers/MetroVPSB2B/lib/Module.php';
require_once __DIR__ . '/../../modules/servers/MetroVPSB2B/lib/ApiClient.php';
require_once __DIR__ . '/../../modules/servers/MetroVPSB2B/lib/Database.php';

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

add_hook('InvoicePaid', 1, function ($vars) {
    $invoiceId = (int) ($vars['invoiceid'] ?? 0);

    if ($invoiceId <= 0) {
        return;
    }

    // Renewal invoices carry 'Hosting' line items for the recurring period.
    $relids = DB::table('tblinvoiceitems')
        ->where('invoiceid', $invoiceId)
        ->where('type', 'Hosting')
        ->pluck('relid')
        ->toArray();

    $module = new \WHMCS\Module\Server\MetroVPSB2B\Module();

    foreach ($relids as $relid) {
        $serviceId = (int) $relid;

        if ($serviceId <= 0) {
            continue;
        }

        $isMetro = DB::table('tblhosting as h')
            ->join('tblproducts as p', 'h.packageid', '=', 'p.id')
            ->where('h.id', $serviceId)
            ->where('p.servertype', 'MetroVPSB2B')
            ->exists();

        if (!$isMetro) {
            continue;
        }

        // Skip the initial purchase invoice — provisioning handles that. A
        // renewal is identifiable by at least one OTHER already-paid 'Hosting'
        // invoice for the same service.
        $priorPaid = DB::table('tblinvoiceitems as ii')
            ->join('tblinvoices as i', 'ii.invoiceid', '=', 'i.id')
            ->where('ii.relid', $serviceId)
            ->where('ii.type', 'Hosting')
            ->where('i.status', 'Paid')
            ->where('ii.invoiceid', '!=', $invoiceId)
            ->exists();

        if (!$priorPaid) {
            continue;
        }

        // Fire-and-forget: a renew failure must never touch WHMCS.
        try {
            $result = $module->renewService($serviceId);

            logModuleCall(
                'MetroVPSB2B',
                'billing:renew',
                ['service_id' => $serviceId, 'invoice_id' => $invoiceId],
                $result,
                ($result['success'] ?? false) ? 'success' : 'error'
            );
        } catch (\Throwable $e) {
            logModuleCall(
                'MetroVPSB2B',
                'billing:renew',
                ['service_id' => $serviceId, 'invoice_id' => $invoiceId],
                $e->getMessage(),
                'error'
            );
        }
    }
});