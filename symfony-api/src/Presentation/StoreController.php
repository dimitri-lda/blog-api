<?php

namespace App\Presentation;

use App\Application\{ApiView,CartService,OrderService};
use App\Entity\{CartItem,Category,Product,ProductVariant,StoreOrder,User};
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\{Cookie,JsonResponse,Request};
use Symfony\Component\Routing\Attribute\Route;

final class StoreController extends ApiController
{
    public function __construct(private readonly EntityManagerInterface $em,private readonly CartService $carts,private readonly OrderService $orders,#[Autowire('%kernel.secret%')]private readonly string $secret,#[Autowire('%app.cookie_secure%')]private readonly bool $secureCookie){}
    #[Route('/api/health',methods:['GET'])] public function health():JsonResponse{return $this->data(['status'=>'ok','service'=>'sportivo-symfony']);}
    #[Route('/api/home',methods:['GET'])]
    public function home():JsonResponse{$categories=$this->em->getRepository(Category::class)->findBy([],['name'=>'ASC']);$featured=$this->em->getRepository(Product::class)->findBy(['featured'=>true,'active'=>true],['createdAt'=>'DESC'],8);return $this->data(['categories'=>array_map(fn($c)=>ApiView::category($c,$this->em->getRepository(Product::class)->count(['category'=>$c,'active'=>true])),$categories),'featured'=>array_map(fn($p)=>ApiView::product($p),$featured)]);}
    #[Route('/api/categories',methods:['GET'])] public function categories():JsonResponse{return $this->data(array_map(fn($c)=>ApiView::category($c,$this->em->getRepository(Product::class)->count(['category'=>$c,'active'=>true])),$this->em->getRepository(Category::class)->findBy([],['name'=>'ASC'])));}
    #[Route('/api/products',methods:['GET'])]
    public function products(Request $request):JsonResponse
    {
        $page=max(1,$request->query->getInt('page',1));$perPage=min(48,max(1,$request->query->getInt('per_page',12)));$qb=$this->em->getRepository(Product::class)->createQueryBuilder('p')->join('p.category','c')->andWhere('p.active = true');
        if($q=trim((string)$request->query->get('q','')))$qb->andWhere('LOWER(p.name) LIKE :q OR LOWER(p.brand) LIKE :q')->setParameter('q','%'.strtolower($q).'%');if($category=$request->query->get('category'))$qb->andWhere('c.slug = :category')->setParameter('category',$category);
        match($request->query->get('sort')){'price_asc'=>$qb->orderBy('p.priceCents','ASC'),'price_desc'=>$qb->orderBy('p.priceCents','DESC'),default=>$qb->orderBy('p.createdAt','DESC')};
        $countQb=clone $qb;$countQb->resetDQLPart('orderBy');$total=(int)$countQb->select('COUNT(p.id)')->getQuery()->getSingleScalarResult();$products=$qb->setFirstResult(($page-1)*$perPage)->setMaxResults($perPage)->getQuery()->getResult();return $this->json(['data'=>array_map(fn($p)=>ApiView::product($p),$products),'meta'=>['current_page'=>$page,'last_page'=>max(1,(int)ceil($total/$perPage)),'per_page'=>$perPage,'total'=>$total]]);
    }
    #[Route('/api/products/{slug}',methods:['GET'])]
    public function product(string $slug):JsonResponse{$product=$this->em->getRepository(Product::class)->findOneBy(['slug'=>$slug,'active'=>true]);if(!$product)return $this->problem('Product not found.',[],404);$related=$this->em->getRepository(Product::class)->createQueryBuilder('p')->andWhere('p.category = :category')->andWhere('p.id != :id')->andWhere('p.active = true')->setParameter('category',$product->getCategory())->setParameter('id',$product->getId())->setMaxResults(4)->getQuery()->getResult();return $this->data(['product'=>ApiView::product($product,true),'related'=>array_map(fn($p)=>ApiView::product($p),$related)]);}
    #[Route('/api/cart',methods:['GET'])]
    public function cart(Request $request):JsonResponse{[$cart,$newToken]=$this->resolveCart($request);$response=$this->data(ApiView::cart($cart));$this->setGuestCookie($response,$newToken);return $response;}
    #[Route('/api/cart/items',methods:['POST'])]
    public function addCartItem(Request $request):JsonResponse{$data=$this->body($request);$variant=$this->em->getRepository(ProductVariant::class)->find((int)($data['variant_id']??0));if(!$variant)return $this->problem('Variant not found.',[],404);$quantity=max(1,min(20,(int)($data['quantity']??1)));[$cart,$newToken]=$this->resolveCart($request);try{$this->carts->add($cart,$variant,$quantity);}catch(\DomainException $e){return $this->problem($e->getMessage());}$response=$this->data(ApiView::cart($cart),201);$this->setGuestCookie($response,$newToken);return $response;}
    #[Route('/api/cart/items/{id}',methods:['PATCH'])]
    public function updateCartItem(int $id,Request $request):JsonResponse{[$cart,$newToken]=$this->resolveCart($request);$item=$this->em->getRepository(CartItem::class)->find($id);if(!$item||!$cart->getItems()->contains($item))return $this->problem('Cart item not found.',[],404);$this->carts->update($cart,$item,(int)($this->body($request)['quantity']??0));$response=$this->data(ApiView::cart($cart));$this->setGuestCookie($response,$newToken);return $response;}
    #[Route('/api/cart/items/{id}',methods:['DELETE'])] public function removeCartItem(int $id,Request $request):JsonResponse{[$cart]=$this->resolveCart($request);$item=$this->em->getRepository(CartItem::class)->find($id);if(!$item||!$cart->getItems()->contains($item))return $this->problem('Cart item not found.',[],404);$this->carts->update($cart,$item,0);return $this->data(ApiView::cart($cart));}
    #[Route('/api/orders',methods:['POST'])]
    public function placeOrder(Request $request):JsonResponse
    {
        $data=$this->body($request);$fields=['email','phone','first_name','last_name','line1','city','postal_code','country','delivery_method'];$errors=$this->missing($data,$fields);if(!filter_var($data['email']??'',FILTER_VALIDATE_EMAIL))$errors['email']=['Enter a valid email.'];if(!in_array($data['delivery_method']??'', ['standard','express'],true))$errors['delivery_method']=['Choose a delivery method.'];if($errors)return $this->problem('Validation failed.',$errors);[$cart,$newToken]=$this->resolveCart($request);try{$order=$this->orders->place($cart,$this->user(),$data);}catch(\DomainException $e){return $this->problem($e->getMessage(),['cart'=>[$e->getMessage()]]);}$response=$this->data(ApiView::order($order),201);$this->setGuestCookie($response,$newToken);if(!$this->user()){$value=$order->getNumber().'.'.hash_hmac('sha256',$order->getNumber(),$this->secret);$response->headers->setCookie(Cookie::create('guest_order_access',$value,new \DateTimeImmutable('+1 day'),'/api/orders',null,$this->secureCookie,true,false,Cookie::SAMESITE_LAX));}return $response;
    }
    #[Route('/api/orders/{number}',methods:['GET'])]
    public function order(string $number,Request $request):JsonResponse{$order=$this->em->getRepository(StoreOrder::class)->findOneBy(['number'=>$number]);if(!$order)return $this->problem('Order not found.',[],404);$allowed=$this->user()&&$order->getUser()?->getId()===$this->user()?->getId();$cookie=$request->cookies->get('guest_order_access','');$expected=$number.'.'.hash_hmac('sha256',$number,$this->secret);if(!$allowed&&!hash_equals($expected,$cookie))return $this->problem('Order not found.',[],404);return $this->data(ApiView::order($order));}
    #[Route('/api/me/orders',methods:['GET'])]
    public function myOrders():JsonResponse{if(!$this->user())return $this->problem('Authentication required.',[],401);$orders=$this->em->getRepository(StoreOrder::class)->findBy(['user'=>$this->user()],['createdAt'=>'DESC']);return $this->data(array_map(fn($o)=>ApiView::order($o,false),$orders));}
    private function resolveCart(Request $request):array{return $this->carts->resolve($this->user(),$request->cookies->get('guest_cart'));}
    private function user():?User{$user=$this->getUser();return $user instanceof User?$user:null;}
    private function setGuestCookie(JsonResponse $response,?string $token):void{if($token)$response->headers->setCookie(Cookie::create('guest_cart',$token,new \DateTimeImmutable('+1 year'),'/api',null,$this->secureCookie,true,false,Cookie::SAMESITE_LAX));}
}
