<?php

namespace TractorCow\Fluent\Tests\Extension;

use Page;
use SilverStripe\CMS\Forms\SiteTreeURLSegmentField;
use SilverStripe\CMS\Model\SiteTree;
use SilverStripe\Control\Director;
use SilverStripe\Core\Config\Config;
use SilverStripe\Dev\SapphireTest;
use SilverStripe\Forms\FieldList;
use SilverStripe\Forms\LiteralField;
use SilverStripe\ORM\ArrayList;
use SilverStripe\ORM\DataObject;
use TractorCow\Fluent\Extension\FluentDirectorExtension;
use TractorCow\Fluent\Extension\FluentSiteTreeExtension;
use TractorCow\Fluent\Extension\FluentVersionedExtension;
use TractorCow\Fluent\Model\Domain;
use TractorCow\Fluent\Model\Locale;
use TractorCow\Fluent\Model\RecordLocale;
use TractorCow\Fluent\State\FluentState;

// Skip if pages module not installed
if (!class_exists(SiteTree::class)) {
    return;
}

class FluentSiteTreeExtensionTest extends SapphireTest
{
    protected static $fixture_file = 'FluentSiteTreeExtensionTest.yml';

    protected static $required_extensions = [
        SiteTree::class => [
            FluentSiteTreeExtension::class,
        ],
    ];

    protected function setUp()
    {
        parent::setUp();
        Config::modify()
            ->set(Director::class, 'alternate_base_url', 'http://mocked')
            ->set(FluentDirectorExtension::class, 'disable_default_prefix', false);

        // Clear cache
        Locale::clearCached();
        Domain::clearCached();
        (new FluentVersionedExtension)->flushCache();

        FluentState::singleton()
            ->setLocale('de_DE')
            ->setIsDomainMode(false);
    }

    public function testGetLocaleInformation()
    {
        FluentState::singleton()->withState(function (FluentState $newState) {
            $newState
                ->setLocale('de_DE')
                ->setIsDomainMode(false);

            /** @var Page|FluentSiteTreeExtension $page */
            $page = $this->objFromFixture(Page::class, 'nz-page');
            $result = $page->LocaleInformation('en_NZ');

            $this->assertInstanceOf(RecordLocale::class, $result);
            $this->assertEquals('en_NZ', $result->getLocale());
            $this->assertEquals('en-nz', $result->getLocaleRFC1766());
            $this->assertEquals('en-nz', $result->getHrefLang());
            $this->assertEquals('English (New Zealand)', $result->getTitle());
            $this->assertEquals('English', $result->getLanguageNative());
            $this->assertEquals('en', $result->getLanguage());
            $this->assertEquals('/newzealand/a-page/', $result->getLink());
            $this->assertEquals('http://mocked/newzealand/a-page/', $result->getAbsoluteLink());
            $this->assertEquals('link', $result->getLinkingMode());
            $this->assertEquals('newzealand', $result->getURLSegment());
        });
    }

    public function testGetLocales()
    {
        FluentState::singleton()->withState(function (FluentState $newState) {
            $newState
                ->setLocale('de_DE')
                ->setIsDomainMode(false);

            /** @var Page|FluentSiteTreeExtension $page */
            $page = $this->objFromFixture(Page::class, 'nz-page');
            $result = $page->Locales();

            $this->assertInstanceOf(ArrayList::class, $result);
            $this->assertCount(5, $result);
            $this->assertListEquals([
                ['Locale' => 'en_NZ'],
                ['Locale' => 'de_DE'],
                ['Locale' => 'en_US'],
                ['Locale' => 'es_ES'],
                ['Locale' => 'zh_CN'],
            ], $result);
        });
    }

    /**
     * Tests for url generation
     *
     * @return array list of tests with values:
     *  - domain (or false for non-domain mode)
     *  - locale
     *  - disable_default_prefix flag
     *  - page id
     *  - expected link
     */
    public function provideURLTests()
    {
        return [
            // Non-domain tests
            [null, 'de_DE', false, 'home', '/german/'],
            [null, 'de_DE', false, 'about', '/german/about-us/'],
            [null, 'de_DE', false, 'staff', '/german/about-us/my-staff/'],
            // Since de_DE is the only locale on the www.example.de domain, ensure that the locale
            // isn't unnecessarily added to the link.
            // In this case disable_default_prefix is ignored
            // See https://github.com/tractorcow/silverstripe-fluent/issues/75
            ['www.example.de', 'de_DE', false, 'home', '/'],
            ['www.example.de', 'de_DE', false, 'about', '/about-us/'],
            ['www.example.de', 'de_DE', false, 'staff', '/about-us/my-staff/'],

            // Test domains with multiple locales
            //  - es_ES non default locale
            ['www.example.com', 'es_ES', false, 'home', '/es_ES/'],
            ['www.example.com', 'es_ES', false, 'about', '/es_ES/about-us/'],
            ['www.example.com', 'es_ES', false, 'staff', '/es_ES/about-us/my-staff/'],
            //  - en_US default locale
            ['www.example.com', 'en_US', false, 'home', '/usa/'],
            ['www.example.com', 'en_US', false, 'about', '/usa/about-us/'],
            ['www.example.com', 'en_US', false, 'staff', '/usa/about-us/my-staff/'],
            //  - en_US default locale, but with disable_default_prefix on
            ['www.example.com', 'en_US', true, 'home', '/'],
            ['www.example.com', 'en_US', true, 'about', '/about-us/'],
            ['www.example.com', 'en_US', true, 'staff', '/about-us/my-staff/'],

            // Test cross-domain links include the opposing domain
            // - to default locale
            ['www.example.de', 'en_US', true, 'home', 'http://www.example.com/'],
            ['www.example.de', 'en_US', true, 'staff', 'http://www.example.com/about-us/my-staff/'],
            // - to non defalut locale
            ['www.example.de', 'es_ES', true, 'home', 'http://www.example.com/es_ES/'],
            ['www.example.de', 'es_ES', true, 'staff', 'http://www.example.com/es_ES/about-us/my-staff/'],
        ];
    }

    /**
     * Test that URLS for pages are generated correctly
     *
     * @dataProvider provideURLTests
     * @param string $domain
     * @param string $locale
     * @param bool $prefixDisabled
     * @param string $pageName
     * @param string $url
     */
    public function testFluentURLs($domain, $locale, $prefixDisabled, $pageName, $url)
    {
        FluentState::singleton()->withState(
            function (FluentState $newState) use ($domain, $locale, $prefixDisabled, $pageName, $url) {
                $newState
                    ->setLocale($locale)
                    ->setDomain($domain)
                    ->setIsDomainMode(!empty($domain));

                // Set url generation option
                Config::modify()
                    ->set(FluentDirectorExtension::class, 'disable_default_prefix', $prefixDisabled);

                /** @var Page|FluentSiteTreeExtension $page */
                $page = $this->objFromFixture(Page::class, $pageName);
                $this->assertEquals($url, $page->Link());
            }
        );
    }

    public function testUpdateStatusFlagsFluentInvisible()
    {
        /** @var Page|FluentSiteTreeExtension $page */
        $page = $this->objFromFixture(Page::class, 'home');
        $flags = $page->getStatusFlags();

        $this->assertArrayHasKey('fluentinvisible', $flags);
    }

    public function testStatusMessageNotVisible()
    {
        FluentState::singleton()->withState(function (FluentState $newState) {
            $newState
                ->setLocale('en_NZ')
                ->setIsDomainMode(false);

            /** @var Page|FluentSiteTreeExtension $page */
            $page = $this->objFromFixture(Page::class, 'staff');
            $page->config()
                ->set('locale_published_status_message', true)
                ->set('frontend_publish_required', true);

            $fields = $page->getCMSFields();

            /** @var LiteralField $statusMessage */
            $statusMessage = $fields->fieldByName('LocaleStatusMessage');

            $this->assertNotNull($statusMessage, 'Locale message was not added');
            $this->assertContains('This page will not be visible', $statusMessage->getContent());
        });
    }

    public function testStatusMessageUnknown()
    {
        FluentState::singleton()->withState(function (FluentState $newState) {
            $newState
                ->setLocale('en_NZ')
                ->setIsDomainMode(false);

            /** @var Page|FluentSiteTreeExtension $page */
            $page = $this->objFromFixture(Page::class, 'home');
            $page->config()
                ->set('locale_published_status_message', true)
                ->set('frontend_publish_required', false);

            $fields = $page->getCMSFields();

            /** @var LiteralField $statusMessage */
            $statusMessage = $fields->fieldByName('LocaleStatusMessage');

            $this->assertNotNull($fields->fieldByName('LocaleStatusMessage'));
            $this->assertContains('No content is available for this page', $statusMessage->getContent());
        });
    }

    public function testStatusMessageDrafted()
    {
        FluentState::singleton()->withState(function (FluentState $newState) {
            $newState
                ->setLocale('en_NZ')
                ->setIsDomainMode(false);

            /** @var Page|FluentSiteTreeExtension $page */
            $page = $this->objFromFixture(Page::class, 'home');
            $page->config()
                ->set('locale_published_status_message', true)
                ->set('frontend_publish_required', false);
            $page->write();

            $fields = $page->getCMSFields();

            /** @var LiteralField $statusMessage */
            $statusMessage = $fields->fieldByName('LocaleStatusMessage');

            $this->assertNotNull($fields->fieldByName('LocaleStatusMessage'));
            $this->assertContains('A draft has been created for this locale', $statusMessage->getContent());
        });
    }

    /**
     * @param string $localeCode
     * @param string $fixture
     * @param string $expected
     * @dataProvider localePrefixUrlProvider
     */
    public function testAddLocalePrefixToUrlSegment($localeCode, $fixture, $expected)
    {
        FluentState::singleton()->withState(
            function (FluentState $newState) use ($localeCode, $fixture, $expected) {
                $newState
                    ->setLocale($localeCode)
                    ->setIsDomainMode(true);

                /** @var FieldList $fields */
                $fields = $this->objFromFixture(Page::class, $fixture)->getCMSFields();

                /** @var SiteTreeURLSegmentField $segmentField */
                $segmentField = $fields->fieldByName('Root.Main.URLSegment');
                $this->assertInstanceOf(SiteTreeURLSegmentField::class, $segmentField);

                $this->assertSame($expected, $segmentField->getURLPrefix());
            }
        );
    }

    public function testHomeVisibleOnFrontendBothConfigFalse()
    {
        Config::modify()->set(DataObject::class, 'cms_localisation_required', false);
        Config::modify()->set(DataObject::class, 'frontend_publish_required', false);

        FluentState::singleton()->withState(function (FluentState $newState) {
            $newState
                ->setLocale('de_DE')
                ->setIsDomainMode(false)
                ->setIsFrontend(true);

            $page = Page::get()->filter('URLSegment', 'home')->first();

            $this->assertNotNull($page);
        });
    }

    public function testHomeVisibleOnFrontendOneConfigFalse()
    {
        Config::modify()->set(DataObject::class, 'cms_localisation_required', true);
        Config::modify()->set(DataObject::class, 'frontend_publish_required', false);

        FluentState::singleton()->withState(function (FluentState $newState) {
            $newState
                ->setLocale('de_DE')
                ->setIsDomainMode(false)
                ->setIsFrontend(true);

            $page = Page::get()->filter('URLSegment', 'home')->first();

            $this->assertNotNull($page);
        });
    }

    public function testHomeNotVisibleOnFrontendBothConfigTrue()
    {
        Config::modify()->set(DataObject::class, 'cms_localisation_required', true);
        Config::modify()->set(DataObject::class, 'frontend_publish_required', true);

        FluentState::singleton()->withState(function (FluentState $newState) {
            $newState
                ->setLocale('de_DE')
                ->setIsDomainMode(false)
                ->setIsFrontend(true);

            $page = Page::get()->filter('URLSegment', 'home')->first();

            $this->assertNull($page);
        });
    }

    public function testHomeNotVisibleOnFrontendOneConfigTrue()
    {
        Config::modify()->set(DataObject::class, 'cms_localisation_required', false);
        Config::modify()->set(DataObject::class, 'frontend_publish_required', true);

        FluentState::singleton()->withState(function (FluentState $newState) {
            $newState
                ->setLocale('de_DE')
                ->setIsDomainMode(false)
                ->setIsFrontend(true);

            $page = Page::get()->filter('URLSegment', 'home')->first();

            $this->assertNull($page);
        });
    }

    public function testHomeVisibleInCMSBothConfigFalse()
    {
        Config::modify()->set(DataObject::class, 'cms_localisation_required', false);
        Config::modify()->set(DataObject::class, 'frontend_publish_required', false);

        FluentState::singleton()->withState(function (FluentState $newState) {
            $newState
                ->setLocale('de_DE')
                ->setIsDomainMode(false)
                ->setIsFrontend(false);

            $page = Page::get()->filter('URLSegment', 'home')->first();

            $this->assertNotNull($page);
        });
    }

    public function testHomeVisibleInCMSOneConfigFalse()
    {
        Config::modify()->set(DataObject::class, 'cms_localisation_required', false);
        Config::modify()->set(DataObject::class, 'frontend_publish_required', true);

        FluentState::singleton()->withState(function (FluentState $newState) {
            $newState
                ->setLocale('de_DE')
                ->setIsDomainMode(false)
                ->setIsFrontend(false);

            $page = Page::get()->filter('URLSegment', 'home')->first();

            $this->assertNotNull($page);
        });
    }

    public function testHomeNotVisibleInCMSBothConfigTrue()
    {
        Config::modify()->set(DataObject::class, 'cms_localisation_required', true);
        Config::modify()->set(DataObject::class, 'frontend_publish_required', true);

        FluentState::singleton()->withState(function (FluentState $newState) {
            $newState
                ->setLocale('de_DE')
                ->setIsDomainMode(false)
                ->setIsFrontend(false);

            $page = Page::get()->filter('URLSegment', 'home')->first();

            $this->assertNull($page);
        });
    }

    public function testHomeNotVisibleInCMSOneConfigTrue()
    {
        Config::modify()->set(DataObject::class, 'cms_localisation_required', true);
        Config::modify()->set(DataObject::class, 'frontend_publish_required', false);

        FluentState::singleton()->withState(function (FluentState $newState) {
            $newState
                ->setLocale('de_DE')
                ->setIsDomainMode(false)
                ->setIsFrontend(false);

            $page = Page::get()->filter('URLSegment', 'home')->first();

            $this->assertNull($page);
        });
    }

    /**
     * @return array[]
     */
    public function localePrefixUrlProvider()
    {
        return [
            'locale_with_domain'            => ['en_US', 'about', 'http://www.example.com/usa/'],
            'locale_without_domain'         => ['zh_CN', 'about', 'http://mocked/zh_CN/'],
            'locale_alone_on_domain_nested' => ['de_DE', 'staff', 'http://www.example.de/about-us/'],
        ];
    }
}
