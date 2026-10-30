<?php

namespace statikbe\headingschecker\controllers;

use Craft;
use craft\elements\Entry;
use craft\web\Controller;
use statikbe\headingschecker\HeadingsChecker;
use yii\web\ForbiddenHttpException;
use yii\web\Response;

/**
 * The Heading outline panel reloads its list here after an autosave, so it shows the headings as edited.
 */
class OutlineController extends Controller
{
    public function actionList(): Response
    {
        $this->requireCpRequest();
        $this->requirePostRequest();
        $this->requireAcceptsJson();

        $entry = Entry::find()
            ->id((int)$this->request->getRequiredBodyParam('elementId'))
            ->siteId((int)$this->request->getRequiredBodyParam('siteId'))
            ->drafts(null)
            ->provisionalDrafts(null)
            ->status(null)
            ->one();
        if (
            $entry === null
            || !Craft::$app->getElements()->canView($entry)
            || (Craft::$app->getIsMultiSite() && !Craft::$app->getUser()->checkPermission('editSite:' . $entry->getSite()->uid))
        ) {
            throw new ForbiddenHttpException('Not allowed to view this entry.');
        }

        return $this->asJson(['html' => HeadingsChecker::getInstance()->outlineListHtml($entry)]);
    }
}
