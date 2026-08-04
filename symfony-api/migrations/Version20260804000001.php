<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260804000001 extends AbstractMigration
{
    public function getDescription():string{return'Align PostgreSQL index names with Doctrine metadata';}
    public function up(Schema $schema):void
    {
        foreach(['cart_variant_unique'=>'UNIQ_CART_VARIANT','uniq_account_token_hash'=>'UNIQ_8D6EDFFDB3BC57DA','uniq_cart_token'=>'UNIQ_4E004AAC5F37A13B','uniq_cart_user'=>'UNIQ_4E004AACA76ED395','uniq_categories_slug'=>'UNIQ_3AF34668989D9B62','uniq_address_order'=>'UNIQ_D34D0EEE8D9F6D38','uniq_products_slug'=>'UNIQ_B3BA5A5A989D9B62','idx_products_category'=>'IDX_B3BA5A5A12469DE2','uniq_variants_sku'=>'UNIQ_78283976F9038C4','idx_variants_product'=>'IDX_782839764584665A','uniq_refresh_hash'=>'UNIQ_9BACE7E1B3BC57DA','uniq_order_number'=>'UNIQ_81386B7896901F54','uniq_users_email'=>'UNIQ_1483A5E9E7927C74']as$from=>$to)$this->addSql("ALTER INDEX {$from} RENAME TO {$to}");
    }
    public function down(Schema $schema):void{}
}
