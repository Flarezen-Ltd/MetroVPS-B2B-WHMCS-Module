<?php

namespace WHMCS\Module\Server\MetroVPSB2B;

use WHMCS\Database\Capsule as DB;

class Database
{
    const TABLE = 'mod_metrovpsb2b';

    /**
     * Ensure the provision-results table exists and has all required columns.
     * Idempotent — safe to call on every module load.
     */
    public static function schema(): void
    {
        if (!DB::schema()->hasTable(self::TABLE)) {
            try {
                DB::schema()->create(self::TABLE, function ($table) {
                    $table->increments('id');
                    $table->unsignedBigInteger('service_id')->index();
                    $table->unsignedBigInteger('b2b_order_id')->nullable()->default(null);
                    $table->unsignedBigInteger('order_id')->nullable()->default(null);
                    $table->unsignedBigInteger('order_uid')->nullable()->default(null);
                    $table->unsignedBigInteger('metro_service_id')->nullable()->default(null);
                    $table->string('invoice_id', 191)->nullable()->default(null);
                    $table->string('status', 64)->nullable()->default(null);
                    $table->string('billing_cycle', 32)->nullable()->default(null);
                    $table->decimal('charged_amount', 10, 2)->nullable()->default(null);
                    $table->decimal('recurring_amount', 10, 2)->nullable()->default(null);
                    $table->string('currency_code', 8)->nullable()->default(null);
                    $table->decimal('balance_remaining', 10, 2)->nullable()->default(null);
                    $table->longText('provision_payload')->nullable()->default(null);
                    $table->longText('provision_response')->nullable()->default(null);
                    $table->text('last_error')->nullable()->default(null);
                    $table->timestamps();
                });
            } catch (\Exception $e) {
                logModuleCall('MetroVPSB2B', 'Database::schema:create', [], $e->getMessage(), '');
            }
        }

        // Add columns that may be missing from older versions of the table
        $expectedColumns = [
            'b2b_order_id'      => 'unsignedBigInteger',
            'order_id'          => 'unsignedBigInteger',
            'order_uid'         => 'unsignedBigInteger',
            'metro_service_id'  => 'unsignedBigInteger',
            'invoice_id'        => 'string',
            'status'            => 'string',
            'billing_cycle'     => 'string',
            'charged_amount'    => 'decimal',
            'recurring_amount'  => 'decimal',
            'currency_code'     => 'string',
            'balance_remaining' => 'decimal',
            'provision_payload' => 'longText',
            'provision_response'=> 'longText',
            'last_error'        => 'text',
            'power_action_at'   => 'datetime',
            'locked_until'      => 'datetime',
        ];

        foreach ($expectedColumns as $column => $type) {
            if (!DB::schema()->hasColumn(self::TABLE, $column)) {
                try {
                    DB::schema()->table(self::TABLE, function ($table) use ($column, $type) {
                        switch ($type) {
                            case 'unsignedBigInteger':
                                $table->unsignedBigInteger($column)->nullable()->default(null);
                                break;
                            case 'string':
                                $table->string($column, 191)->nullable()->default(null);
                                break;
                            case 'decimal':
                                $table->decimal($column, 10, 2)->nullable()->default(null);
                                break;
                            case 'longText':
                                $table->longText($column)->nullable()->default(null);
                                break;
                            case 'text':
                                $table->text($column)->nullable()->default(null);
                                break;
                            case 'datetime':
                                $table->datetime($column)->nullable()->default(null);
                                break;
                        }
                    });
                } catch (\Exception $e) {
                    logModuleCall('MetroVPSB2B', 'Database::schema:addColumn:' . $column, [], $e->getMessage(), '');
                }
            }
        }
    }

    /**
     * Save a successful provision result.
     */
    public static function saveProvisionResult(int $serviceId, array $responseData, array $payload): void
    {
        $data = [
            'b2b_order_id'       => $responseData['b2b_order_id'] ?? null,
            'order_id'           => $responseData['order_id'] ?? null,
            'order_uid'          => $responseData['order_uid'] ?? null,
            'metro_service_id'   => $responseData['service_id'] ?? null,
            'invoice_id'         => $responseData['invoice_id'] ?? null,
            'status'             => $responseData['status'] ?? null,
            'billing_cycle'      => $responseData['billing_cycle'] ?? null,
            'charged_amount'     => $responseData['charged_amount'] ?? null,
            'recurring_amount'   => $responseData['recurring_amount'] ?? null,
            'currency_code'      => $responseData['currency_code'] ?? null,
            'balance_remaining'  => $responseData['balance_remaining'] ?? null,
            'provision_payload'  => json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES),
            'provision_response' => json_encode($responseData, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES),
            'last_error'         => null,
            'updated_at'         => date('Y-m-d H:i:s'),
        ];

        self::upsert($serviceId, $data);
    }

    /**
     * Save a failed provision attempt.
     */
    public static function saveProvisionError(int $serviceId, string $error, array $payload): void
    {
        $data = [
            'status'             => 'failed',
            'provision_payload'  => json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES),
            'last_error'         => $error,
            'updated_at'         => date('Y-m-d H:i:s'),
        ];

        self::upsert($serviceId, $data);
    }

    /**
     * Upsert a row by service_id.
     */
    protected static function upsert(int $serviceId, array $data): void
    {
        try {
            $exists = DB::table(self::TABLE)->where('service_id', $serviceId)->exists();

            if ($exists) {
                DB::table(self::TABLE)->where('service_id', $serviceId)->update($data);
            } else {
                $data['service_id'] = $serviceId;
                $data['created_at'] = date('Y-m-d H:i:s');

                if (!isset($data['updated_at'])) {
                    $data['updated_at'] = $data['created_at'];
                }

                DB::table(self::TABLE)->insert($data);
            }
        } catch (\Exception $e) {
            logModuleCall('MetroVPSB2B', 'Database::upsert', ['service_id' => $serviceId], $e->getMessage(), '');
        }
    }

    /**
     * Fetch the provision record for a WHMCS service.
     */
    public static function getByServiceId(int $serviceId): ?object
    {
        try {
            return DB::table(self::TABLE)->where('service_id', $serviceId)->first();
        } catch (\Exception $e) {
            logModuleCall('MetroVPSB2B', 'Database::getByServiceId', ['service_id' => $serviceId], $e->getMessage(), '');
            return null;
        }
    }

    /**
     * Look up a provision record by the MetroVPS B2B order ID.
     *
     * @param int $b2bOrderId
     * @return object|null
     */
    public static function getByB2bOrderId(int $b2bOrderId): ?object
    {
        try {
            return DB::table(self::TABLE)->where('b2b_order_id', $b2bOrderId)->first();
        } catch (\Exception $e) {
            logModuleCall('MetroVPSB2B', 'Database::getByB2bOrderId', ['b2b_order_id' => $b2bOrderId], $e->getMessage(), '');
            return null;
        }
    }

    /**
     * Update the provision record with data from the callback/vps-details API.
     *
     * @param int $serviceId  WHMCS tblhosting.id
     * @param array $vpsData  Response data from getVpsDetails
     */
    public static function updateCallbackResult(int $serviceId, array $vpsData): void
    {
        $data = [
            'status'             => $vpsData['service_status'] ?? null,
            'provision_response' => json_encode($vpsData, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES),
            'last_error'         => null,
            'updated_at'         => date('Y-m-d H:i:s'),
        ];

        try {
            $exists = DB::table(self::TABLE)->where('service_id', $serviceId)->exists();

            if ($exists) {
                DB::table(self::TABLE)->where('service_id', $serviceId)->update($data);
            } else {
                $data['service_id'] = $serviceId;
                $data['b2b_order_id'] = $vpsData['b2b_order_id'] ?? null;
                $data['metro_service_id'] = $vpsData['service_id'] ?? null;
                $data['created_at'] = date('Y-m-d H:i:s');
                DB::table(self::TABLE)->insert($data);
            }
        } catch (\Exception $e) {
            logModuleCall('MetroVPSB2B', 'Database::updateCallbackResult', ['service_id' => $serviceId], $e->getMessage(), '');
        }
    }

    /**
     * Link a WHMCS service to an existing (externally-created) MetroVPS VPS.
     *
     * Always writes the MetroVPS service id, even when a row already exists,
     * so re-running "Connect Existing" overwrites the previous link.
     *
     * @param int   $whmcsServiceId WHMCS tblhosting.id
     * @param int   $metroServiceId MetroVPS service ID of the existing VPS
     * @param array $vpsData        Response data from getExistingVpsDetails
     */
    public static function importExistingVps(int $whmcsServiceId, int $metroServiceId, array $vpsData): void
    {
        $data = [
            'metro_service_id'   => $metroServiceId,
            'b2b_order_id'       => $vpsData['b2b_order_id'] ?? null,
            'status'             => $vpsData['service_status'] ?? 'active',
            'provision_response' => json_encode($vpsData, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES),
            'last_error'         => null,
            'updated_at'         => date('Y-m-d H:i:s'),
        ];

        try {
            $exists = DB::table(self::TABLE)->where('service_id', $whmcsServiceId)->exists();

            if ($exists) {
                DB::table(self::TABLE)->where('service_id', $whmcsServiceId)->update($data);
            } else {
                $data['service_id'] = $whmcsServiceId;
                $data['created_at'] = date('Y-m-d H:i:s');
                DB::table(self::TABLE)->insert($data);
            }
        } catch (\Exception $e) {
            logModuleCall('MetroVPSB2B', 'Database::importExistingVps', ['service_id' => $whmcsServiceId], $e->getMessage(), '');
        }
    }

    /**
     * Set (or clear) the power-action lock timestamp for a service.
     *
     * @param int         $serviceId
     * @param string|null $when 'Y-m-d H:i:s' timestamp, or null to clear
     */
    public static function setPowerActionAt(int $serviceId, ?string $when = null): void
    {
        try {
            $exists = DB::table(self::TABLE)->where('service_id', $serviceId)->exists();

            if ($exists) {
                DB::table(self::TABLE)->where('service_id', $serviceId)->update(['power_action_at' => $when]);
            } else {
                DB::table(self::TABLE)->insert([
                    'service_id'      => $serviceId,
                    'power_action_at' => $when,
                    'created_at'      => date('Y-m-d H:i:s'),
                    'updated_at'      => date('Y-m-d H:i:s'),
                ]);
            }
        } catch (\Exception $e) {
            logModuleCall('MetroVPSB2B', 'Database::setPowerActionAt', ['service_id' => $serviceId, 'when' => $when], $e->getMessage(), '');
        }
    }

    /**
     * Set (or clear) the rebuild lock expiry for a service.
     *
     * @param int         $serviceId
     * @param string|null $until Absolute 'Y-m-d H:i:s' expiry, or null to clear
     */
    public static function setLockedUntil(int $serviceId, ?string $until = null): void
    {
        try {
            $exists = DB::table(self::TABLE)->where('service_id', $serviceId)->exists();

            if ($exists) {
                DB::table(self::TABLE)->where('service_id', $serviceId)->update(['locked_until' => $until]);
            } else {
                DB::table(self::TABLE)->insert([
                    'service_id'   => $serviceId,
                    'locked_until' => $until,
                    'created_at'   => date('Y-m-d H:i:s'),
                    'updated_at'   => date('Y-m-d H:i:s'),
                ]);
            }
        } catch (\Exception $e) {
            logModuleCall('MetroVPSB2B', 'Database::setLockedUntil', ['service_id' => $serviceId, 'until' => $until], $e->getMessage(), '');
        }
    }

    /**
     * Remove the provision record for a terminated service.
     */
    public static function deleteByServiceId(int $serviceId): void
    {
        try {
            DB::table(self::TABLE)->where('service_id', $serviceId)->delete();
        } catch (\Exception $e) {
            logModuleCall('MetroVPSB2B', 'Database::deleteByServiceId', ['service_id' => $serviceId], $e->getMessage(), '');
        }
    }
}