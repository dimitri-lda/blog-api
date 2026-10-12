<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;
use Doctrine\Migrations\Exception\IrreversibleMigration;

final class Version20261012001000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Upgrade a Laravel schema that predates Shop Pay and inventory hardening';
    }

    public function up(Schema $schema): void
    {
        $this->abortIf(
            !$this->connection->getDatabasePlatform() instanceof PostgreSQLPlatform,
            'This migration supports PostgreSQL only.',
        );

        $this->addSql(
            'ALTER TABLE orders ADD COLUMN IF NOT EXISTS '
            .'inventory_reserved_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL',
        );
        $this->addSql(
            'ALTER TABLE orders ADD COLUMN IF NOT EXISTS '
            .'inventory_released_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL',
        );
        foreach ([
            'categories_parent_id_index' => 'categories (parent_id)',
            'products_category_id_index' => 'products (category_id)',
            'products_brand_id_index' => 'products (brand_id)',
            'products_manufacturer_id_index' => 'products (manufacturer_id)',
            'product_variants_product_id_index' => 'product_variants (product_id)',
            'product_images_product_id_index' => 'product_images (product_id)',
            'product_specifications_product_id_index' => 'product_specifications (product_id)',
            'carts_user_id_index' => 'carts (user_id)',
            'cart_items_cart_id_index' => 'cart_items (cart_id)',
            'cart_items_product_variant_id_index' => 'cart_items (product_variant_id)',
            'orders_user_id_index' => 'orders (user_id)',
            'order_items_order_id_index' => 'order_items (order_id)',
            'order_items_product_variant_id_index' => 'order_items (product_variant_id)',
        ] as $index => $target) {
            $this->addSql("CREATE INDEX IF NOT EXISTS {$index} ON {$target}");
        }

        $this->addSql(<<<'SQL'
CREATE TABLE IF NOT EXISTS payment_sessions (
    id BIGSERIAL NOT NULL,
    order_id BIGINT NOT NULL,
    provider VARCHAR(255) DEFAULT 'shop_pay' NOT NULL,
    source_identifier VARCHAR(255) NOT NULL,
    session_token TEXT DEFAULT NULL,
    session_token_hash CHAR(64) DEFAULT NULL,
    checkout_url TEXT DEFAULT NULL,
    receipt_token TEXT DEFAULT NULL,
    status VARCHAR(255) DEFAULT 'pending' NOT NULL,
    provider_order_id VARCHAR(255) DEFAULT NULL,
    last_error TEXT DEFAULT NULL,
    paid_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL,
    created_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL,
    updated_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL,
    PRIMARY KEY (id)
)
SQL);
        $this->addSql(
            'CREATE UNIQUE INDEX IF NOT EXISTS payment_sessions_order_id_unique '
            .'ON payment_sessions (order_id)',
        );
        $this->addSql(
            'CREATE UNIQUE INDEX IF NOT EXISTS payment_sessions_source_identifier_unique '
            .'ON payment_sessions (source_identifier)',
        );
        $this->addSql(
            'CREATE UNIQUE INDEX IF NOT EXISTS payment_sessions_session_token_hash_unique '
            .'ON payment_sessions (session_token_hash)',
        );

        $this->addSql(<<<'SQL'
CREATE TABLE IF NOT EXISTS payment_attempts (
    id BIGSERIAL NOT NULL,
    payment_session_id BIGINT NOT NULL,
    payment_method_hash CHAR(64) NOT NULL,
    amount_cents INTEGER DEFAULT NULL,
    currency VARCHAR(3) DEFAULT NULL,
    idempotency_key UUID NOT NULL,
    payload TEXT NOT NULL,
    receipt_token TEXT DEFAULT NULL,
    status VARCHAR(255) DEFAULT 'pending' NOT NULL,
    last_error TEXT DEFAULT NULL,
    created_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL,
    updated_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL,
    PRIMARY KEY (id)
)
SQL);
        $this->addSql(
            'ALTER TABLE payment_attempts ADD COLUMN IF NOT EXISTS amount_cents INTEGER DEFAULT NULL',
        );
        $this->addSql(
            'ALTER TABLE payment_attempts ADD COLUMN IF NOT EXISTS currency VARCHAR(3) DEFAULT NULL',
        );
        $this->addSql(
            'CREATE INDEX IF NOT EXISTS payment_attempts_payment_session_id_index '
            .'ON payment_attempts (payment_session_id)',
        );
        $this->addSql(
            'CREATE UNIQUE INDEX IF NOT EXISTS payment_attempts_idempotency_key_unique '
            .'ON payment_attempts (idempotency_key)',
        );
        $this->addSql(
            'CREATE UNIQUE INDEX IF NOT EXISTS payment_attempts_session_method_unique '
            .'ON payment_attempts (payment_session_id, payment_method_hash)',
        );

        $this->addSql(<<<'SQL'
CREATE TABLE IF NOT EXISTS shopify_webhook_deliveries (
    id BIGSERIAL NOT NULL,
    delivery_id UUID NOT NULL,
    topic VARCHAR(255) NOT NULL,
    processed_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL,
    created_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL,
    updated_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL,
    PRIMARY KEY (id)
)
SQL);
        $this->addSql(
            'CREATE UNIQUE INDEX IF NOT EXISTS shopify_webhook_deliveries_delivery_id_unique '
            .'ON shopify_webhook_deliveries (delivery_id)',
        );

        $this->addSql(<<<'SQL'
UPDATE payment_attempts pa
SET
    amount_cents = o.total_cents,
    currency = o.currency
FROM payment_sessions ps
INNER JOIN orders o ON o.id = ps.order_id
WHERE pa.payment_session_id = ps.id
  AND (pa.amount_cents IS NULL OR pa.currency IS NULL)
SQL);
        $this->addSql(<<<'SQL'
UPDATE order_items oi
SET product_variant_id = NULL
WHERE product_variant_id IS NOT NULL
  AND NOT EXISTS (
      SELECT 1 FROM product_variants pv WHERE pv.id = oi.product_variant_id
  )
SQL);

        $this->addConstraintIfMissing(
            'payment_sessions_order_id_foreign',
            'ALTER TABLE payment_sessions ADD CONSTRAINT payment_sessions_order_id_foreign '
            .'FOREIGN KEY (order_id) REFERENCES orders (id) ON DELETE CASCADE',
        );
        $this->addConstraintIfMissing(
            'payment_attempts_session_id_foreign',
            'ALTER TABLE payment_attempts ADD CONSTRAINT payment_attempts_session_id_foreign '
            .'FOREIGN KEY (payment_session_id) REFERENCES payment_sessions (id) ON DELETE CASCADE',
        );
        $this->addConstraintIfMissing(
            'order_items_product_variant_id_foreign',
            'ALTER TABLE order_items ADD CONSTRAINT order_items_product_variant_id_foreign '
            .'FOREIGN KEY (product_variant_id) REFERENCES product_variants (id) ON DELETE SET NULL',
        );
    }

    public function down(Schema $schema): void
    {
        throw new IrreversibleMigration(
            'Compatibility migration cannot distinguish pre-existing payment data safely.',
        );
    }

    private function addConstraintIfMissing(string $name, string $statement): void
    {
        $escapedName = $this->connection->quote($name);
        $escapedStatement = str_replace("'", "''", $statement);
        $this->addSql(<<<SQL
DO $$
BEGIN
    IF NOT EXISTS (
        SELECT 1 FROM pg_constraint WHERE conname = {$escapedName}
    ) THEN
        EXECUTE '{$escapedStatement}';
    END IF;
END
$$
SQL);
    }
}
