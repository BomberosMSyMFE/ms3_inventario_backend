<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     * Crea el usuario de solo lectura para el protocolo FastMCP (Punto 09 INF512)
     * y le otorga exclusivamente permisos SELECT con transacción forzada read-only.
     */
    public function up(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            try {
                $dbName = config('database.connections.pgsql.database', 'postgres');
                DB::statement("
                    DO $$
                    BEGIN
                        IF NOT EXISTS (SELECT FROM pg_catalog.pg_roles WHERE rolname = 'mcp_readonly_user') THEN
                            CREATE ROLE mcp_readonly_user WITH LOGIN PASSWORD 'mcp_read_secret_2026';
                        END IF;
                    END
                    $$;
                ");

                DB::statement("GRANT CONNECT ON DATABASE \"{$dbName}\" TO mcp_readonly_user;");
                DB::statement("GRANT USAGE ON SCHEMA public TO mcp_readonly_user;");
                DB::statement("GRANT SELECT ON ALL TABLES IN SCHEMA public TO mcp_readonly_user;");
                DB::statement("ALTER DEFAULT PRIVILEGES IN SCHEMA public GRANT SELECT ON TABLES TO mcp_readonly_user;");
                DB::statement("ALTER ROLE mcp_readonly_user SET default_transaction_read_only = on;");
            } catch (\Throwable $e) {
                // En nubes administradas (Supabase/Neon), se ignora si los permisos de superusuario son restringidos
                \Illuminate\Support\Facades\Log::warning("Aviso mcp_readonly_user: " . $e->getMessage());
            }
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::statement("REVOKE ALL PRIVILEGES ON ALL TABLES IN SCHEMA public FROM mcp_readonly_user;");
            DB::statement("REVOKE ALL PRIVILEGES ON SCHEMA public FROM mcp_readonly_user;");
            DB::statement("DROP ROLE IF EXISTS mcp_readonly_user;");
        }
    }
};
