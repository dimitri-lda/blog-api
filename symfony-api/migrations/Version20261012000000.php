<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261012000000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Create the business schema compatible with the final Laravel schema';
    }

    public function up(Schema $schema): void
    {
        $this->abortIf(
            !$this->connection->getDatabasePlatform() instanceof PostgreSQLPlatform,
            'This migration supports PostgreSQL only.',
        );

        $this->addSql(<<<'SQL'
CREATE TABLE users (
    id BIGSERIAL NOT NULL,
    name VARCHAR(255) NOT NULL,
    email VARCHAR(255) NOT NULL,
    email_verified_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL,
    password VARCHAR(255) NOT NULL,
    accepted_terms BOOLEAN DEFAULT FALSE NOT NULL,
    terms_accepted_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL,
    role VARCHAR(255) DEFAULT 'user' NOT NULL,
    remember_token VARCHAR(100) DEFAULT NULL,
    created_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL,
    updated_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL,
    PRIMARY KEY (id)
)
SQL);
        $this->addSql('CREATE UNIQUE INDEX users_email_unique ON users (email)');

        $this->addSql(<<<'SQL'
CREATE TABLE password_reset_tokens (
    email VARCHAR(255) NOT NULL,
    token VARCHAR(255) NOT NULL,
    created_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL,
    PRIMARY KEY (email)
)
SQL);

        $this->addSql(<<<'SQL'
CREATE TABLE categories (
    id BIGSERIAL NOT NULL,
    parent_id BIGINT DEFAULT NULL,
    name VARCHAR(255) NOT NULL,
    name_translations JSON DEFAULT NULL,
    slug VARCHAR(255) NOT NULL,
    image_url VARCHAR(255) DEFAULT NULL,
    created_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL,
    updated_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL,
    PRIMARY KEY (id)
)
SQL);
        $this->addSql('CREATE INDEX categories_parent_id_index ON categories (parent_id)');
        $this->addSql('CREATE UNIQUE INDEX categories_slug_unique ON categories (slug)');

        foreach (['brands', 'manufacturers'] as $table) {
            $this->addSql(<<<SQL
CREATE TABLE {$table} (
    id BIGSERIAL NOT NULL,
    name VARCHAR(255) NOT NULL,
    slug VARCHAR(255) NOT NULL,
    created_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL,
    updated_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL,
    PRIMARY KEY (id)
)
SQL);
            $this->addSql("CREATE UNIQUE INDEX {$table}_slug_unique ON {$table} (slug)");
        }

        $this->addSql(<<<'SQL'
CREATE TABLE products (
    id BIGSERIAL NOT NULL,
    category_id BIGINT NOT NULL,
    brand_id BIGINT DEFAULT NULL,
    manufacturer_id BIGINT DEFAULT NULL,
    name VARCHAR(255) NOT NULL,
    name_translations JSON DEFAULT NULL,
    slug VARCHAR(255) NOT NULL,
    description TEXT NOT NULL,
    description_translations JSON DEFAULT NULL,
    price_cents_net INTEGER NOT NULL,
    compare_at_price_cents_net INTEGER DEFAULT NULL,
    brand VARCHAR(255) DEFAULT NULL,
    image_url VARCHAR(255) NOT NULL,
    featured BOOLEAN DEFAULT FALSE NOT NULL,
    active BOOLEAN DEFAULT TRUE NOT NULL,
    created_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL,
    updated_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL,
    PRIMARY KEY (id)
)
SQL);
        $this->addSql('CREATE INDEX products_category_id_index ON products (category_id)');
        $this->addSql('CREATE INDEX products_brand_id_index ON products (brand_id)');
        $this->addSql('CREATE INDEX products_manufacturer_id_index ON products (manufacturer_id)');
        $this->addSql('CREATE UNIQUE INDEX products_slug_unique ON products (slug)');

        $this->addSql(<<<'SQL'
CREATE TABLE product_variants (
    id BIGSERIAL NOT NULL,
    product_id BIGINT NOT NULL,
    name VARCHAR(255) NOT NULL,
    name_translations JSON DEFAULT NULL,
    sku VARCHAR(255) NOT NULL,
    price_cents_net INTEGER DEFAULT NULL,
    stock INTEGER DEFAULT 0 NOT NULL,
    created_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL,
    updated_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL,
    PRIMARY KEY (id)
)
SQL);
        $this->addSql('CREATE INDEX product_variants_product_id_index ON product_variants (product_id)');
        $this->addSql('CREATE UNIQUE INDEX product_variants_sku_unique ON product_variants (sku)');

        $this->addSql(<<<'SQL'
CREATE TABLE product_images (
    id BIGSERIAL NOT NULL,
    product_id BIGINT NOT NULL,
    url VARCHAR(255) NOT NULL,
    alt VARCHAR(255) DEFAULT NULL,
    position SMALLINT DEFAULT 0 NOT NULL,
    created_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL,
    updated_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL,
    PRIMARY KEY (id)
)
SQL);
        $this->addSql('CREATE INDEX product_images_product_id_index ON product_images (product_id)');

        $this->addSql(<<<'SQL'
CREATE TABLE product_specifications (
    id BIGSERIAL NOT NULL,
    product_id BIGINT NOT NULL,
    name VARCHAR(255) NOT NULL,
    value VARCHAR(255) NOT NULL,
    unit VARCHAR(255) DEFAULT NULL,
    position SMALLINT DEFAULT 0 NOT NULL,
    created_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL,
    updated_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL,
    PRIMARY KEY (id)
)
SQL);
        $this->addSql(
            'CREATE INDEX product_specifications_product_id_index ON product_specifications (product_id)',
        );

        $this->addSql(<<<'SQL'
CREATE TABLE carts (
    id BIGSERIAL NOT NULL,
    user_id BIGINT DEFAULT NULL,
    token UUID DEFAULT NULL,
    created_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL,
    updated_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL,
    PRIMARY KEY (id)
)
SQL);
        $this->addSql('CREATE INDEX carts_user_id_index ON carts (user_id)');
        $this->addSql('CREATE UNIQUE INDEX carts_token_unique ON carts (token)');

        $this->addSql(<<<'SQL'
CREATE TABLE cart_items (
    id BIGSERIAL NOT NULL,
    cart_id BIGINT NOT NULL,
    product_variant_id BIGINT NOT NULL,
    quantity INTEGER NOT NULL,
    created_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL,
    updated_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL,
    PRIMARY KEY (id)
)
SQL);
        $this->addSql('CREATE INDEX cart_items_cart_id_index ON cart_items (cart_id)');
        $this->addSql('CREATE INDEX cart_items_product_variant_id_index ON cart_items (product_variant_id)');
        $this->addSql(
            'CREATE UNIQUE INDEX cart_items_cart_id_product_variant_id_unique '
            .'ON cart_items (cart_id, product_variant_id)',
        );

        $this->addSql(<<<'SQL'
CREATE TABLE orders (
    id BIGSERIAL NOT NULL,
    user_id BIGINT DEFAULT NULL,
    number VARCHAR(255) NOT NULL,
    email VARCHAR(255) NOT NULL,
    phone VARCHAR(255) NOT NULL,
    status VARCHAR(255) DEFAULT 'pending_payment' NOT NULL,
    delivery_method VARCHAR(255) NOT NULL,
    subtotal_cents INTEGER NOT NULL,
    delivery_cents INTEGER NOT NULL,
    total_cents INTEGER NOT NULL,
    currency VARCHAR(3) DEFAULT 'EUR' NOT NULL,
    market VARCHAR(20) DEFAULT NULL,
    locale VARCHAR(5) DEFAULT NULL,
    base_currency VARCHAR(3) DEFAULT 'EUR' NOT NULL,
    exchange_rate NUMERIC(18, 8) DEFAULT NULL,
    vat_rate_basis_points SMALLINT DEFAULT NULL,
    net_subtotal_cents INTEGER DEFAULT NULL,
    tax_cents INTEGER DEFAULT NULL,
    delivery_net_cents INTEGER DEFAULT NULL,
    delivery_tax_cents INTEGER DEFAULT NULL,
    inventory_reserved_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL,
    inventory_released_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL,
    created_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL,
    updated_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL,
    PRIMARY KEY (id)
)
SQL);
        $this->addSql('CREATE INDEX orders_user_id_index ON orders (user_id)');
        $this->addSql('CREATE UNIQUE INDEX orders_number_unique ON orders (number)');

        $this->addSql(<<<'SQL'
CREATE TABLE order_addresses (
    id BIGSERIAL NOT NULL,
    order_id BIGINT NOT NULL,
    first_name VARCHAR(255) NOT NULL,
    last_name VARCHAR(255) NOT NULL,
    line1 VARCHAR(255) NOT NULL,
    line2 VARCHAR(255) DEFAULT NULL,
    city VARCHAR(255) NOT NULL,
    postal_code VARCHAR(255) NOT NULL,
    country VARCHAR(2) NOT NULL,
    created_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL,
    updated_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL,
    PRIMARY KEY (id)
)
SQL);
        $this->addSql('CREATE UNIQUE INDEX order_addresses_order_id_unique ON order_addresses (order_id)');

        $this->addSql(<<<'SQL'
CREATE TABLE order_items (
    id BIGSERIAL NOT NULL,
    order_id BIGINT NOT NULL,
    product_variant_id BIGINT DEFAULT NULL,
    name VARCHAR(255) NOT NULL,
    variant_name VARCHAR(255) NOT NULL,
    unit_price_cents INTEGER NOT NULL,
    net_unit_price_cents INTEGER DEFAULT NULL,
    quantity INTEGER NOT NULL,
    line_total_cents INTEGER NOT NULL,
    tax_cents INTEGER DEFAULT NULL,
    created_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL,
    updated_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL,
    PRIMARY KEY (id)
)
SQL);
        $this->addSql('CREATE INDEX order_items_order_id_index ON order_items (order_id)');
        $this->addSql(
            'CREATE INDEX order_items_product_variant_id_index ON order_items (product_variant_id)',
        );

        $this->addSql(<<<'SQL'
CREATE TABLE saved_addresses (
    id BIGSERIAL NOT NULL,
    user_id BIGINT NOT NULL,
    first_name VARCHAR(80) NOT NULL,
    last_name VARCHAR(80) NOT NULL,
    line1 VARCHAR(180) NOT NULL,
    line2 VARCHAR(180) DEFAULT NULL,
    city VARCHAR(80) NOT NULL,
    postal_code VARCHAR(20) NOT NULL,
    country VARCHAR(2) NOT NULL,
    created_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL,
    updated_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL,
    PRIMARY KEY (id)
)
SQL);
        $this->addSql('CREATE UNIQUE INDEX saved_addresses_user_id_unique ON saved_addresses (user_id)');

        $this->addSql(<<<'SQL'
CREATE TABLE exchange_rates (
    id BIGSERIAL NOT NULL,
    currency VARCHAR(3) NOT NULL,
    rate NUMERIC(18, 8) NOT NULL,
    source VARCHAR(40) NOT NULL,
    quoted_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL,
    created_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL,
    updated_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL,
    PRIMARY KEY (id)
)
SQL);
        $this->addSql('CREATE UNIQUE INDEX exchange_rates_currency_unique ON exchange_rates (currency)');

        $this->addSql(<<<'SQL'
CREATE TABLE payment_sessions (
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
        $this->addSql('CREATE UNIQUE INDEX payment_sessions_order_id_unique ON payment_sessions (order_id)');
        $this->addSql(
            'CREATE UNIQUE INDEX payment_sessions_source_identifier_unique '
            .'ON payment_sessions (source_identifier)',
        );
        $this->addSql(
            'CREATE UNIQUE INDEX payment_sessions_session_token_hash_unique '
            .'ON payment_sessions (session_token_hash)',
        );

        $this->addSql(<<<'SQL'
CREATE TABLE payment_attempts (
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
            'CREATE INDEX payment_attempts_payment_session_id_index '
            .'ON payment_attempts (payment_session_id)',
        );
        $this->addSql(
            'CREATE UNIQUE INDEX payment_attempts_idempotency_key_unique '
            .'ON payment_attempts (idempotency_key)',
        );
        $this->addSql(
            'CREATE UNIQUE INDEX payment_attempts_session_method_unique '
            .'ON payment_attempts (payment_session_id, payment_method_hash)',
        );

        $this->addSql(<<<'SQL'
CREATE TABLE shopify_webhook_deliveries (
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
            'CREATE UNIQUE INDEX shopify_webhook_deliveries_delivery_id_unique '
            .'ON shopify_webhook_deliveries (delivery_id)',
        );

        $this->addForeignKeys();
    }

    public function down(Schema $schema): void
    {
        $this->abortIf(
            !$this->connection->getDatabasePlatform() instanceof PostgreSQLPlatform,
            'This migration supports PostgreSQL only.',
        );

        foreach ([
            'payment_attempts',
            'payment_sessions',
            'shopify_webhook_deliveries',
            'saved_addresses',
            'order_items',
            'order_addresses',
            'orders',
            'cart_items',
            'carts',
            'product_specifications',
            'product_images',
            'product_variants',
            'products',
            'manufacturers',
            'brands',
            'categories',
            'exchange_rates',
            'password_reset_tokens',
            'users',
        ] as $table) {
            $this->addSql("DROP TABLE {$table}");
        }
    }

    private function addForeignKeys(): void
    {
        $this->addSql(
            'ALTER TABLE categories ADD CONSTRAINT categories_parent_id_foreign '
            .'FOREIGN KEY (parent_id) REFERENCES categories (id) ON DELETE SET NULL',
        );
        $this->addSql(
            'ALTER TABLE products ADD CONSTRAINT products_category_id_foreign '
            .'FOREIGN KEY (category_id) REFERENCES categories (id) ON DELETE RESTRICT',
        );
        $this->addSql(
            'ALTER TABLE products ADD CONSTRAINT products_brand_id_foreign '
            .'FOREIGN KEY (brand_id) REFERENCES brands (id) ON DELETE RESTRICT',
        );
        $this->addSql(
            'ALTER TABLE products ADD CONSTRAINT products_manufacturer_id_foreign '
            .'FOREIGN KEY (manufacturer_id) REFERENCES manufacturers (id) ON DELETE RESTRICT',
        );
        $this->addSql(
            'ALTER TABLE product_variants ADD CONSTRAINT product_variants_product_id_foreign '
            .'FOREIGN KEY (product_id) REFERENCES products (id) ON DELETE CASCADE',
        );
        $this->addSql(
            'ALTER TABLE product_images ADD CONSTRAINT product_images_product_id_foreign '
            .'FOREIGN KEY (product_id) REFERENCES products (id) ON DELETE CASCADE',
        );
        $this->addSql(
            'ALTER TABLE product_specifications ADD CONSTRAINT product_specifications_product_id_foreign '
            .'FOREIGN KEY (product_id) REFERENCES products (id) ON DELETE CASCADE',
        );
        $this->addSql(
            'ALTER TABLE carts ADD CONSTRAINT carts_user_id_foreign '
            .'FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE',
        );
        $this->addSql(
            'ALTER TABLE cart_items ADD CONSTRAINT cart_items_cart_id_foreign '
            .'FOREIGN KEY (cart_id) REFERENCES carts (id) ON DELETE CASCADE',
        );
        $this->addSql(
            'ALTER TABLE cart_items ADD CONSTRAINT cart_items_product_variant_id_foreign '
            .'FOREIGN KEY (product_variant_id) REFERENCES product_variants (id) ON DELETE RESTRICT',
        );
        $this->addSql(
            'ALTER TABLE orders ADD CONSTRAINT orders_user_id_foreign '
            .'FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE SET NULL',
        );
        $this->addSql(
            'ALTER TABLE order_addresses ADD CONSTRAINT order_addresses_order_id_foreign '
            .'FOREIGN KEY (order_id) REFERENCES orders (id) ON DELETE CASCADE',
        );
        $this->addSql(
            'ALTER TABLE order_items ADD CONSTRAINT order_items_order_id_foreign '
            .'FOREIGN KEY (order_id) REFERENCES orders (id) ON DELETE CASCADE',
        );
        $this->addSql(
            'ALTER TABLE order_items ADD CONSTRAINT order_items_product_variant_id_foreign '
            .'FOREIGN KEY (product_variant_id) REFERENCES product_variants (id) ON DELETE SET NULL',
        );
        $this->addSql(
            'ALTER TABLE saved_addresses ADD CONSTRAINT saved_addresses_user_id_foreign '
            .'FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE',
        );
        $this->addSql(
            'ALTER TABLE payment_sessions ADD CONSTRAINT payment_sessions_order_id_foreign '
            .'FOREIGN KEY (order_id) REFERENCES orders (id) ON DELETE CASCADE',
        );
        $this->addSql(
            'ALTER TABLE payment_attempts ADD CONSTRAINT payment_attempts_session_id_foreign '
            .'FOREIGN KEY (payment_session_id) REFERENCES payment_sessions (id) ON DELETE CASCADE',
        );
    }
}
