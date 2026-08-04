<?php

namespace App\Application;

use App\Entity\{Cart, Category, Product, StoreOrder, User};

final class ApiView
{
    public static function user(User $user): array { return ['id'=>$user->getId(),'name'=>$user->getName(),'email'=>$user->getEmail(),'roles'=>$user->getRoles(),'email_verified'=>$user->isEmailVerified()]; }
    public static function category(Category $category, ?int $count=null): array { $data=['id'=>$category->getId(),'name'=>$category->getName(),'slug'=>$category->getSlug(),'image_url'=>$category->getImageUrl()]; if($count!==null)$data['products_count']=$count; return $data; }
    public static function product(Product $product, bool $details=false): array
    {
        $data=['id'=>$product->getId(),'name'=>$product->getName(),'slug'=>$product->getSlug(),'brand'=>$product->getBrand(),'description'=>$product->getDescription(),'price_cents'=>$product->getPriceCents(),'compare_at_price_cents'=>$product->getCompareAtPriceCents(),'image_url'=>$product->getImageUrl(),'featured'=>$product->isFeatured(),'active'=>$product->isActive(),'category'=>self::category($product->getCategory()),'variants'=>array_map(fn($v)=>['id'=>$v->getId(),'name'=>$v->getName(),'sku'=>$v->getSku(),'price_cents'=>$v->getPriceCents(),'stock'=>$v->getStock()],$product->getVariants()->toArray())];
        if($details)$data['images']=array_map(fn($i)=>['id'=>$i->getId(),'url'=>$i->getUrl(),'position'=>$i->getPosition()],$product->getImages()->toArray());
        return $data;
    }
    public static function cart(Cart $cart): array
    {
        $items=array_map(function($item){$variant=$item->getVariant();$price=$variant->getPriceCents()??$variant->getProduct()->getPriceCents();return['id'=>$item->getId(),'quantity'=>$item->getQuantity(),'variant_id'=>$variant->getId(),'variant_name'=>$variant->getName(),'name'=>$variant->getProduct()->getName(),'image_url'=>$variant->getProduct()->getImageUrl(),'unit_price_cents'=>$price,'line_total_cents'=>$price*$item->getQuantity()];},$cart->getItems()->toArray());
        return ['items'=>$items,'subtotal_cents'=>array_sum(array_column($items,'line_total_cents')),'count'=>array_sum(array_column($items,'quantity'))];
    }
    public static function order(StoreOrder $order, bool $details=true): array
    {
        $data=['id'=>$order->getId(),'number'=>$order->getNumber(),'email'=>$order->getEmail(),'phone'=>$order->getPhone(),'status'=>$order->getStatus()->value,'delivery_method'=>$order->getDeliveryMethod()->value,'subtotal_cents'=>$order->getSubtotalCents(),'delivery_cents'=>$order->getDeliveryCents(),'total_cents'=>$order->getTotalCents(),'currency'=>$order->getCurrency(),'created_at'=>$order->getCreatedAt()->format(DATE_ATOM)];
        if($details){$data['address']=$order->getAddress()?->toArray();$data['items']=array_map(fn($item)=>$item->toArray(),$order->getItems()->toArray());$data['customer']=$order->getUser()?self::user($order->getUser()):null;}
        return $data;
    }
}
