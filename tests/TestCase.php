<?php

namespace Tests;

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use RuntimeException;

abstract class TestCase extends BaseTestCase
{
    public function createApplication(): Application
    {
        $app = parent::createApplication();

        if ($app['config']->get('database.default') !== 'mysql'
            || $app['config']->get('database.connections.mysql.database') !== 'hako_ra_campus_test') {
            throw new RuntimeException('Les tests exigent la base MySQL dédiée hako_ra_campus_test.');
        }
        // Journal des effacements : les tests écrivent dans un fichier jetable, jamais dans le journal du poste.
        $app['config']->set('campus.privacy.journal_path', storage_path('framework/testing/erasure-journal-'.getmypid().'.jsonl'));
        // Version en service : les tests ne lisent ni n'écrivent jamais le fichier REVISION réel du poste.
        $app['config']->set('campus.revision_file', storage_path('framework/testing/REVISION-absent-'.getmypid()));

        return $app;
    }

    protected function tearDown(): void
    {
        @unlink(storage_path('framework/testing/erasure-journal-'.getmypid().'.jsonl'));
        parent::tearDown();
    }

    /**
     * Repart d'un appareil vierge : sans utilisateur mémorisé, sans session en mémoire et sans cookie.
     * Sans cela, les requêtes successives d'un même test partagent la session du précédent utilisateur.
     */
    protected function freshDevice(): static
    {
        $this->app['auth']->forgetGuards();
        $this->app->forgetInstance('auth.driver');
        $store = $this->app['session.store'];
        $store->flush();
        $store->setId(null);
        $this->defaultCookies = [];

        return $this;
    }
}
