<?php

namespace App\DataFixtures;

use App\Entity\{Category, Product, ProductVariant, User};
use Doctrine\Bundle\FixturesBundle\Fixture;
use Doctrine\Persistence\ObjectManager;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

final class AppFixtures extends Fixture
{
    public function __construct(private readonly UserPasswordHasherInterface $hasher)
    {
    }

    public function load(ObjectManager $manager): void
    {
        foreach ([['Test User', 'test@example.com', ['ROLE_USER']], ['Store Admin', 'admin@example.com', ['ROLE_ADMIN']], ['Super Admin', 'superadmin@example.com', ['ROLE_SUPERADMIN']]] as [$name, $email, $roles]) {
            $user = new User($name, $email);
            $user->setRoles($roles);
            $user->acceptTerms();
            $user->verifyEmail();
            $user->setPassword($this->hasher->hashPassword($user, 'password'));
            $manager->persist($user);
        }
        $categoryData = [
            'running' => ['Running', 'https://images.unsplash.com/photo-1552674605-db6ffd4facb5?auto=format&fit=crop&w=700&q=80'],
            'fitness' => ['Fitness', 'https://images.unsplash.com/photo-1534438327276-14e5300c3a48?auto=format&fit=crop&w=700&q=80'],
            'tennis' => ['Racket sports', 'https://images.unsplash.com/photo-1622279457486-62dcc4a431d6?auto=format&fit=crop&w=700&q=80'],
            'outdoor' => ['Outdoor', 'https://images.unsplash.com/photo-1551632811-561732d1e306?auto=format&fit=crop&w=700&q=80'],
        ];
        $categories = [];
        foreach ($categoryData as $slug => [$name, $image]) {
            $categories[$slug] = new Category($name, $slug, $image);
            $manager->persist($categories[$slug]);
        }
        $products = [
            ['running', 'Cloudswift 4', 'On', 'cloudswift-4', 16900, 'A responsive everyday runner with soft landings and confident grip.', 'https://images.unsplash.com/photo-1542291026-7eec264c27ff?auto=format&fit=crop&w=900&q=85', true],
            ['fitness', 'Move Training Mat', 'daoSport', 'move-training-mat', 4900, 'A grippy, comfortable mat for your home and studio practice.', 'https://images.unsplash.com/photo-1592432678016-e910b452f9a2?auto=format&fit=crop&w=900&q=85', true],
            ['tennis', 'Pure Aero 98', 'Babolat', 'pure-aero-98', 22900, 'Spin, precision and feel for the player who controls every point.', 'https://images.unsplash.com/photo-1595435934249-5df7ed86e1c0?auto=format&fit=crop&w=900&q=85', true],
            ['outdoor', 'Trail Shell Jacket', 'Salomon', 'trail-shell-jacket', 13900, 'Lightweight weather protection built for changing mountain days.', 'https://images.unsplash.com/photo-1551698618-1dfe5d97d256?auto=format&fit=crop&w=900&q=85', true],
            ['running', 'Aero Run Shorts', 'Nike', 'aero-run-shorts', 4500, 'Light, breathable shorts that stay out of your way.', 'https://images.unsplash.com/photo-1552902865-b72c031ac5ea?auto=format&fit=crop&w=900&q=85', false],
            ['fitness', 'Kettlebell 12kg', 'daoSport', 'kettlebell-12kg', 5900, 'A durable cast iron kettlebell for full-body strength sessions.', 'https://images.unsplash.com/photo-1517963879433-6ad2b056d712?auto=format&fit=crop&w=900&q=85', false],
            ['tennis', 'Court Backpack', 'Wilson', 'court-backpack', 7900, 'Smart storage for your racket, kit and everyday essentials.', 'https://images.unsplash.com/photo-1553062407-98eeb64c6a62?auto=format&fit=crop&w=900&q=85', false],
            ['outdoor', 'Alpine Daypack 24L', 'Osprey', 'alpine-daypack', 10900, 'A comfortable, versatile daypack for every trail.', 'https://images.unsplash.com/photo-1551632811-561732d1e306?auto=format&fit=crop&w=900&q=85', false],
        ];
        foreach ($products as [$category, $name, $brand, $slug, $price, $description, $image, $featured]) {
            $product = new Product($categories[$category], $name, $slug, $brand, $description, $price, $image, $featured);
            $manager->persist($product);
            $manager->persist(new ProductVariant($product, 'One size', strtoupper($slug) . '-ONE', $price, 24));
        }
        $manager->flush();
    }
}
