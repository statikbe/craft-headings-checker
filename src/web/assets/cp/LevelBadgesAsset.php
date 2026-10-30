<?php

namespace statikbe\headingschecker\web\assets\cp;

use craft\web\AssetBundle;

/**
 * The heading level badges in rich text fields (settings → levelBadges).
 */
class LevelBadgesAsset extends AssetBundle
{
    public function init(): void
    {
        $this->sourcePath = __DIR__ . '/dist';
        $this->css = ['badges.css'];
        parent::init();
    }
}
