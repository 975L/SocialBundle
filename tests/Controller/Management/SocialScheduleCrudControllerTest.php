<?php

/*
 * (c) 2026: 975L <contact@975l.com>
 * (c) 2026: Laurent Marquet <laurent.marquet@laposte.net>
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 */

namespace c975L\SocialBundle\Tests\Controller\Management;

use c975L\ConfigBundle\Service\ConfigServiceInterface;
use c975L\SocialBundle\Controller\Management\SocialScheduleCrudController;
use c975L\SocialBundle\Service\SocialPublisher;
use EasyCorp\Bundle\EasyAdminBundle\Config\Action;
use EasyCorp\Bundle\EasyAdminBundle\Config\Actions;
use EasyCorp\Bundle\EasyAdminBundle\Config\Crud;
use EasyCorp\Bundle\EasyAdminBundle\Contracts\Field\FieldInterface;
use EasyCorp\Bundle\EasyAdminBundle\Field\ChoiceField;
use PHPUnit\Framework\TestCase;

class SocialScheduleCrudControllerTest extends TestCase
{
    private function createController(): SocialScheduleCrudController
    {
        $configService = $this->createStub(ConfigServiceInterface::class);
        $configService->method('get')->willReturnCallback(static fn (string $key) => 'site-role-editor' === $key ? 'ROLE_EDITOR' : null);

        $publisher = $this->createStub(SocialPublisher::class);
        $publisher->method('getSourceChoices')->willReturn(['gallery_media' => 'Gallery media', 'gallery_media:3' => 'Gallery media - 2024', 'gallery_media:7' => 'Gallery media - 2024 (#7)']);
        $publisher->method('getNetworkNames')->willReturn(['bluesky', 'facebook']);

        return new SocialScheduleCrudController($configService, $publisher);
    }

    // The choices a field offers, by property
    private function choices(string $property): array
    {
        foreach ($this->createController()->configureFields(Crud::PAGE_NEW) as $field) {
            \assert($field instanceof FieldInterface);
            if ($property === $field->getAsDto()->getProperty()) {
                return $field->getAsDto()->getCustomOption(ChoiceField::OPTION_CHOICES);
            }
        }

        return [];
    }

    public function testEveryActionDemandsSiteRoleEditor(): void
    {
        $actions = $this->createController()->configureActions(
            Actions::new()
                ->add(Crud::PAGE_INDEX, Action::NEW)
                ->add(Crud::PAGE_INDEX, Action::EDIT)
                ->add(Crud::PAGE_INDEX, Action::DELETE)
        );
        $permissions = $actions->getAsDto(null)->getActionPermissions();

        foreach ([Action::INDEX, Action::NEW, Action::EDIT, Action::DELETE] as $action) {
            $this->assertSame('ROLE_EDITOR', $permissions[$action], $action);
        }
        $this->assertContains(Action::DETAIL, $actions->getAsDto(null)->getDisabledActions());
    }

    // Labelled by the publisher, stored by value - two groups of the same name staying two choices
    public function testTheSourcesAreTheChoicesThePublisherOffers(): void
    {
        $this->assertSame(['Gallery media' => 'gallery_media', 'Gallery media - 2024' => 'gallery_media:3', 'Gallery media - 2024 (#7)' => 'gallery_media:7'], $this->choices('sources'));
    }

    public function testTheNetworksAreEveryOneAPublisherExistsFor(): void
    {
        $this->assertSame(['Bluesky' => 'bluesky', 'Facebook' => 'facebook'], $this->choices('networks'));
    }
}
