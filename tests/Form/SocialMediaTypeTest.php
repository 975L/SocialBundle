<?php

/*
 * (c) 2026: 975L <contact@975l.com>
 * (c) 2026: Laurent Marquet <laurent.marquet@laposte.net>
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 */

namespace c975L\SocialBundle\Tests\Form;

use c975L\SocialBundle\Entity\SocialMedia;
use c975L\SocialBundle\Form\SocialMediaType;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Form\Extension\Validator\ValidatorExtension;
use Symfony\Component\Form\Forms;
use Symfony\Component\Validator\Validation;
use Symfony\Contracts\Translation\TranslatorInterface;
use Vich\UploaderBundle\Form\Type\VichFileType;
use Vich\UploaderBundle\Handler\UploadHandlerInterface;
use Vich\UploaderBundle\Mapping\PropertyMappingFactoryInterface;
use Vich\UploaderBundle\Storage\StorageInterface;

class SocialMediaTypeTest extends TestCase
{
    // A media's form offering the networks given, Vich's own file field standing beside it
    private function submit(SocialMedia $media, string $network): SocialMedia
    {
        $factory = Forms::createFormFactoryBuilder()
            ->addExtension(new ValidatorExtension(Validation::createValidator()))
            ->addType(new SocialMediaType($this->createStub(TranslatorInterface::class)))
            ->addType(new VichFileType($this->createStub(StorageInterface::class), $this->createStub(UploadHandlerInterface::class), $this->createStub(PropertyMappingFactoryInterface::class)))
            ->getFormFactory();
        $form = $factory->create(SocialMediaType::class, $media, ['networks' => ['Bluesky' => 'bluesky', 'Instagram' => 'instagram']]);
        $form->submit(['network' => $network, 'alt' => '', 'position' => '0'], false);

        return $media;
    }

    // A network chosen keeps the media for it, none keeping it for every network again
    public function testTheNetworkChosenKeepsTheMediaForIt(): void
    {
        $media = new SocialMedia();

        $this->assertSame('bluesky', $this->submit($media, 'bluesky')->getNetwork());
        $this->assertNull($this->submit($media, '')->getNetwork());
    }
}
