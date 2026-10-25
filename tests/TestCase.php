<?php

declare(strict_types=1);

namespace Atannex\Scopable\Tests;

use Atannex\Scopable\Registry;
use Atannex\Scopable\ScopableServiceProvider;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Orchestra\Testbench\TestCase as Orchestra;

abstract class TestCase extends Orchestra
{
    protected function setUp(): void
    {
        parent::setUp();

        Registry::flush();
    }

    protected function getPackageProviders($app): array
    {
        return [ScopableServiceProvider::class];
    }

    protected function defineEnvironment($app): void
    {
        $app['config']->set('database.default', 'testing');
        $app['config']->set('app.timezone', 'UTC');
    }

    protected function defineDatabaseMigrations(): void
    {
        Schema::create('categories', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->boolean('is_visible')->nullable();
            $table->timestamps();
        });

        Schema::create('articles', function (Blueprint $table) {
            $table->id();
            $table->string('title');
            $table->foreignId('category_id')->nullable();
            $table->string('flag')->nullable();
            $table->boolean('is_featured')->nullable();
            $table->timestamp('published_at')->nullable();
            $table->boolean('is_breaking')->default(false);
            $table->timestamp('breaking_at')->nullable();
            $table->timestamp('breaking_expires')->nullable();
            $table->integer('order')->default(0);
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('campaigns', function (Blueprint $table) {
            $table->id();
            $table->string('status')->nullable();
            $table->timestamp('starts_at')->nullable();
            $table->timestamp('ends_at')->nullable();
            $table->integer('impressions')->nullable();
            $table->integer('max_impressions')->nullable();
            $table->decimal('spend', 10, 2)->nullable();
            $table->decimal('budget', 10, 2)->nullable();
            $table->timestamps();
        });

        Schema::create('tokens', function (Blueprint $table) {
            $table->id();
            $table->timestamp('expires_at')->nullable();
            $table->timestamp('last_used_at')->nullable();
            $table->timestamps();
        });

        Schema::create('notes', function (Blueprint $table) {
            $table->id();
            $table->timestamp('published_at')->nullable();
            $table->boolean('is_pinned')->nullable();
            $table->timestamps();
        });
    }
}
