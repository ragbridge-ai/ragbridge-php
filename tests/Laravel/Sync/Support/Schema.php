<?php

declare(strict_types=1);

namespace Ragbridge\Tests\Laravel\Sync\Support;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema as SchemaFacade;

/**
 * Creates and drops the tables of the fixture models, in an in-memory database that starts
 * empty for every test.
 */
final class Schema
{
    private function __construct() {}

    public static function create(): void
    {
        SchemaFacade::create('posts', static function (Blueprint $table): void {
            $table->id();
            $table->string('title');
            $table->text('body');
            $table->boolean('published')->default(true);
            $table->timestamps();
            $table->softDeletes();
        });

        SchemaFacade::create('tagged_posts', static function (Blueprint $table): void {
            $table->id();
            $table->string('title');
            $table->text('body');
            $table->timestamps();
        });

        SchemaFacade::create('restricted_posts', static function (Blueprint $table): void {
            $table->id();
            $table->string('title');
            $table->text('body');
            $table->boolean('visible')->default(true);
            $table->timestamps();
        });
    }

    public static function drop(): void
    {
        SchemaFacade::dropIfExists('posts');
        SchemaFacade::dropIfExists('tagged_posts');
        SchemaFacade::dropIfExists('restricted_posts');
    }
}
