<?php

declare(strict_types=1);

/*
 * This file is part of the Rollerworks AppSectioningBundle package.
 *
 * (c) Sebastiaan Stok <s.stok@rollerscapes.net>
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 */

namespace Rollerworks\Component\AppSectioning\Tests\Routing;

use PHPUnit\Framework\TestCase;
use Rollerworks\Component\AppSectioning\Routing\AppSectionRouteLoader;
use Symfony\Component\Config\Loader\LoaderInterface;
use Symfony\Component\Config\Loader\LoaderResolverInterface;
use Symfony\Component\Routing\Route;
use Symfony\Component\Routing\RouteCollection;

final class AppSectionRouteLoaderTest extends TestCase
{
    /**
     * @var AppSectionRouteLoader
     */
    private $loader;

    private const APP_SECTIONS = [
        'api' => [
            'is_secure' => true,
            'prefix' => 'api/',
            'requirements' => [],
            'defaults' => [],
        ],
        'frontend' => [
            'is_secure' => false,
            'prefix' => '/',
            'requirements' => [],
            'defaults' => [],
        ],
        'backend' => [
            'is_secure' => true,
            'prefix' => '/',
            'host' => 'example.{tld}',
            'requirements' => ['tld' => 'net|com'],
            'defaults' => ['tld' => 'com'],
        ],
    ];

    /**
     * @before
     */
    public function createLoader()
    {
        $loader = $this->prophesize(LoaderInterface::class);
        $loader->load('something.yml', null)->will(
            function () {
                $routeCollection = new RouteCollection();
                $routeCollection->add('frontend_news', new Route('news/'));
                $routeCollection->add('frontend_blog', new Route('blog/'));

                return $routeCollection;
            }
        );

        $xmlLoader = $this->prophesize(LoaderInterface::class);
        $xmlLoader->load('something.xml', 'xml')->will(
            function () {
                $routeCollection = new RouteCollection();
                $routeCollection->add('backend_main', new Route('/'));
                $routeCollection->add('backend_user', new Route('user/'));

                return $routeCollection;
            }
        );

        $resolver = $this->prophesize(LoaderResolverInterface::class);
        $resolver->resolve('something.yml', null)->willReturn($loader);
        $resolver->resolve('something.xml', 'xml')->willReturn($xmlLoader);

        $this->loader = new AppSectionRouteLoader($resolver->reveal(), self::APP_SECTIONS);
    }

    /**
     * @test
     * @dataProvider provideSupported
     */
    public function it_returns_true_when_its_supported($resource)
    {
        $this->assertTrue($this->loader->supports($resource, 'app_section'));
    }

    public function provideSupported()
    {
        return [
            'Resource without type' => ['frontend#something.yml'],
            'Resource with type' => ['frontend:xml#something.xml'],
            'Resource in other section' => ['api#something.yml'],
        ];
    }

    /**
     * @test
     * @dataProvider provideUnsupported
     */
    public function it_returns_false_when_its_not_supported($resource, $type = 'app_section')
    {
        $this->assertFalse($this->loader->supports($resource, $type));
    }

    public function provideUnsupported()
    {
        return [
            'Wrong type (empty)' => ['something.yml', null],
            'Wrong type (yml)' => ['something.yml', 'yml'],
        ];
    }

    /**
     * @test
     */
    public function it_loads_routing_with_config_applied()
    {
        $routeCollection1 = new RouteCollection();
        $routeCollection1->add('frontend_news', new Route('news/'));
        $routeCollection1->add('frontend_blog', new Route('blog/'));

        $routeCollection2 = new RouteCollection();
        $routeCollection2->add('backend_main', new Route('/', ['tld' => 'com'], ['tld' => 'net|com'], [], 'example.{tld}'));
        $routeCollection2->add('backend_user', new Route('user/', ['tld' => 'com'], ['tld' => 'net|com'], [], 'example.{tld}'));
        $routeCollection2->setSchemes(['https']);

        $routeCollection3 = new RouteCollection();
        $routeCollection3->add('frontend_news', new Route('api/news/'));
        $routeCollection3->add('frontend_blog', new Route('api/blog/'));
        $routeCollection3->setSchemes(['https']);

        $this->assertEquals($routeCollection1, $this->loader->load('frontend#something.yml'));
        $this->assertEquals($routeCollection2, $this->loader->load('backend:xml#something.xml'));
        $this->assertEquals($routeCollection3, $this->loader->load('api#something.yml'));
        $this->assertEquals($routeCollection3, $this->loader->load('api#something.yml'));
    }

    /**
     * @test
     * @dataProvider provideInvalid
     */
    public function it_throws_an_exception_when_the_resource_is_invalid($value)
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage(
            sprintf(
                'This is not a valid section resource "%s", '.
                'expected format is "section-name#actual-resource" or "section-name:type#actual-resource".',
                $value
            )
        );

        $this->loader->load($value);
    }

    public function provideInvalid()
    {
        return [
            'Wrong format (missing # and type)' => ['frontend:something.xml'],
            'Wrong format (missing type value)' => ['frontend:#something.xml'],
        ];
    }

    /**
     * @test
     */
    public function it_throws_an_exception_when_the_section_is_unregistered()
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('No section was registered with name "foo"');

        $this->loader->load('foo#something.yml');
    }

    /**
     * @test
     */
    public function it_throws_an_exception_when_the_attempting_to_import_another_section()
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Unable to import app-section route collection with type "app_section".');

        $this->loader->load('api:app_section#frontend');
    }
}
