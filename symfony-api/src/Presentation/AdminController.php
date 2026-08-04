<?php

namespace App\Presentation;

use App\Application\ApiView;
use App\Domain\Orders\OrderStatus;
use App\Entity\StoreOrder;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\{JsonResponse,Request};
use Symfony\Component\Routing\Attribute\Route;

#[Route('/api/admin/orders')]
final class AdminController extends ApiController
{
    public function __construct(private readonly EntityManagerInterface $em){}
    #[Route('',methods:['GET'])]
    public function index(Request $request):JsonResponse{$page=max(1,$request->query->getInt('page',1));$perPage=20;$qb=$this->em->getRepository(StoreOrder::class)->createQueryBuilder('o');if($q=trim((string)$request->query->get('q','')))$qb->andWhere('LOWER(o.number) LIKE :q OR LOWER(o.email) LIKE :q')->setParameter('q','%'.strtolower($q).'%');if($status=OrderStatus::tryFrom((string)$request->query->get('status','')))$qb->andWhere('o.status = :status')->setParameter('status',$status);$count=clone $qb;$count->resetDQLPart('orderBy');$total=(int)$count->select('COUNT(o.id)')->getQuery()->getSingleScalarResult();$orders=$qb->orderBy('o.createdAt','DESC')->setFirstResult(($page-1)*$perPage)->setMaxResults($perPage)->getQuery()->getResult();return $this->json(['data'=>array_map(fn($o)=>ApiView::order($o,false),$orders),'meta'=>['current_page'=>$page,'last_page'=>max(1,(int)ceil($total/$perPage)),'per_page'=>$perPage,'total'=>$total],'filters'=>['q'=>$request->query->get('q',''),'status'=>$request->query->get('status','')],'statuses'=>array_column(OrderStatus::cases(),'value')]);}
    #[Route('/{id}',methods:['GET'])] public function show(int $id):JsonResponse{$order=$this->em->getRepository(StoreOrder::class)->find($id);return $order?$this->data(['order'=>ApiView::order($order),'next_statuses'=>array_column($order->getStatus()->allowedNext(),'value')]):$this->problem('Order not found.',[],404);}
    #[Route('/{id}/status',methods:['PATCH'])] public function status(int $id,Request $request):JsonResponse{$order=$this->em->getRepository(StoreOrder::class)->find($id);if(!$order)return $this->problem('Order not found.',[],404);$target=OrderStatus::tryFrom((string)($this->body($request)['status']??''));if(!$target)return $this->problem('Validation failed.',['status'=>['Choose a valid status.']]);try{$order->transitionTo($target);}catch(\DomainException $e){return $this->problem($e->getMessage(),['status'=>[$e->getMessage()]]);}$this->em->flush();return $this->data(ApiView::order($order));}
}
