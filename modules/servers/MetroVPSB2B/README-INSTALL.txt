MetroVPS B2B — WHMCS Server Module
==================================

Sell and provision VPS services from the MetroVPS reseller platform
(https://dash.metrovps.com) directly inside WHMCS.

------------------------------------------------------------
1. INSTALL
------------------------------------------------------------

Unzip this archive at your WHMCS root directory so the paths merge
into place:

    modules/servers/MetroVPSB2B/            (server module)
    includes/hooks/metrovpsb2b_cart.php     (cart + client area hooks)
    includes/hooks/metrovpsb2b_billing.php  (billing suspend/unsuspend hooks)

All three paths are required — the cart hook handles the order-form
"Operating System" dropdown and the client area Overview widget; the
billing hook connects overdue-invoice suspension and paid-invoice
unsuspension to the MetroVPS suspend/unsuspend APIs.

No database restore is needed. The module table (mod_metrovpsb2b)
is created and migrated automatically on first load.

------------------------------------------------------------
2. CONFIGURE — SERVER
------------------------------------------------------------

WHMCS Admin -> Setup -> Products/Services -> Servers -> Add New Server

    Name            : anything (e.g. MetroVPS B2B)
    Type            : MetroVPSB2B
    Hostname        : https://dash.metrovps.com
    Username        : (unused)
    Password        : your B2B API key
    Access Hash     : your B2B API secret

Use the "Test Connection" button to verify the credentials.

------------------------------------------------------------
3. CONFIGURE — PRODUCT
------------------------------------------------------------

Create a product in WHMCS Admin -> Setup -> Products/Services:

    Product Type   : any server type
    Module         : MetroVPSB2B
    Server Group   : a group containing the server from step 2

In the product's "Module Settings" tab, choose the MetroVPS package
from the "Package" dropdown.

The "Operating System" dropdown is added to the order form
automatically by the hook on first configuration.

------------------------------------------------------------
4. ACTIVATION FLOW
------------------------------------------------------------

- On service activation, WHMCS calls POST /b2b/api/provision-vps.
- The module sends the callback URL as webhook_url automatically:

      {SystemURL}/modules/servers/MetroVPSB2B/callback.php

- MetroVPS calls that URL with:

      event=vps.activated&service_id=X&b2b_order_id=Y   -> syncs data and
                                                           sends the welcome
                                                           email
      event=vps.updated  ...                            -> syncs data only
      event=vps.rebuilt  ...                            -> syncs data, emails
                                                           new credentials, and
                                                           clears the action lock

- Welcome emails are suppressed at provision time; they are sent only
  when the vps.activated event confirms the VPS is ready. Rebuild and
  password-reset emails are sent directly by the module.

------------------------------------------------------------
5. BILLING / SUSPENSION
------------------------------------------------------------

Admin suspend and unsuspend on the service page call the MetroVPS
suspend/unsuspend APIs immediately (with the selected reason).

The billing hook additionally drives automatic billing suspensions:

  - Overdue invoices: when WHMCS Automation Settings has
    "Enable Suspension" on, the daily cron suspends MetroVPS VPS
    services whose unpaid invoice is older than the configured
    "Suspension Days" grace period.
  - Paid invoices: as soon as an invoice is paid (with
    "Enable Unsuspension" on), a Suspended metroVPS VPS is
    unsuspended immediately — no waiting for the next cron run.

While suspended, all client actions are disabled and the power
badge is hidden on the dashboard.

------------------------------------------------------------
6. CLIENT AREA
------------------------------------------------------------

The product details page shows a VPS dashboard (Overview widget and
Manage tab): lifecycle status, power state, IP/credentials with
password reveal, OS/kernel, package specs, plus actions:

    Power On/Off, Restart        (60s cooldown)
    Reinstall (OS template)      (3 min lock, "Installing" state)
    Reset Password               (new password emailed)

The admin service page additionally has Sync VPS Details, power
actions and Reset Password under Module Commands, plus provision
IDs, financials and power state on the module tab.

Connect Existing VPS: for a service whose VPS was not created from
WHMCS, open the service's Module tab and click "Connect Existing VPS".
Enter the VPS's MetroVPS service ID; the module fetches the VPS via
GET /b2b/api/vps-details?service_id=..&existing=true and imports the
IP, root password, hostname and status into WHMCS.

------------------------------------------------------------
7. SUPPORT
------------------------------------------------------------

Module log: WHMCS Admin -> Utilities -> Logs -> Module Log
(module name "MetroVPSB2B"). All API calls are logged there.