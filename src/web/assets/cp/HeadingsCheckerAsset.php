<?php

namespace statikbe\headingschecker\web\assets\cp;

use craft\web\AssetBundle;
use craft\web\assets\cp\CpAsset;
use craft\web\View;

/**
 * The Heading outline panel in the entry sidebar.
 */
class HeadingsCheckerAsset extends AssetBundle
{
    public function init(): void
    {
        $this->sourcePath = __DIR__ . '/dist';
        $this->depends = [CpAsset::class];
        $this->css = ['outline.css'];
        $this->js = ['outline.js'];
        parent::init();
    }

    public function registerAssetFiles($view): void
    {
        parent::registerAssetFiles($view);
        if ($view instanceof View) {
            $view->registerTranslations('headings-checker', [
                '{count, plural, =1{# issue} other{# issues}}',
            ]);
        }
    }
}
