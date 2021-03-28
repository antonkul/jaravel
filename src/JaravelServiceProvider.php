<?php

declare(strict_types=1);

namespace Umbrellio\Jaravel;

use Illuminate\Console\Events\CommandFinished;
use Illuminate\Console\Events\CommandStarting;
use Illuminate\Contracts\Bus\Dispatcher;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Facades\Config as ConfigRepository;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;
use Jaeger\Config;
use OpenTracing\Tracer;
use Umbrellio\Jaravel\Listeners\ConsoleCommandFinishedListener;
use Umbrellio\Jaravel\Listeners\ConsoleCommandStartedListener;
use Umbrellio\Jaravel\Services\ConsoleCommandFilter;
use Umbrellio\Jaravel\Services\Job\JobWithTracingInjectionDispatcher;

class JaravelServiceProvider extends ServiceProvider
{
    public function boot()
    {
        $config = __DIR__ . '/config/jaravel.php';

        $this->publishes([
            $config => base_path('config/jaravel.php'),
        ], 'config');

        $this->configureTracer();

        if (!ConfigRepository::get('jaravel.enabled', false)) {
            return;
        }

        $this->listenLogs();
        $this->listenConsoleEvents();
        $this->extendJobsDispatcher();
    }

    public function extendJobsDispatcher(): void
    {
        $dispatcher = $this->app->make(Dispatcher::class);
        $this->app->extend(Dispatcher::class, function () use ($dispatcher) {
            return $this->app->make(JobWithTracingInjectionDispatcher::class, [
                'dispatcher' => $dispatcher,
            ]);
        });
    }

    private function configureTracer(): void
    {
        if ($tracerCallable = ConfigRepository::get('jaravel.custom_tracer_callable', null)) {
            $this->app->singleton(Tracer::class, $tracerCallable);

            return;
        }

        $config = Config::getInstance();

        if (!ConfigRepository::get('jaravel.enabled', false)) {
            $config->setDisabled(true);
        }

        $tracer = $config->initTracer(
            ConfigRepository::get('jaravel.tracer_name', 'application'),
            ConfigRepository::get('jaravel.agent_host_port', '127.0.0.1:6831')
        );

        $this->app->instance(Tracer::class, $tracer);
    }

    private function listenLogs(): void
    {
        if (!ConfigRepository::get('jaravel.logs_enabled', true)) {
            return;
        }

        Event::listen(MessageLogged::class, function (MessageLogged $e) {
            $span = $this->app->make(Tracer::class)->getActiveSpan();
            if (!$span) {
                return;
            }

            $span->log([
                'message' => $e->message,
                'context' => $e->context,
                'level' => $e->level,
            ]);
        });
    }

    private function listenConsoleEvents(): void
    {
        if (!$this->app->runningInConsole()) {
            return;
        }

        /** @var ConsoleCommandFilter $filter */
        $filter = $this->app->make(ConsoleCommandFilter::class);

        if (!$filter->allow()) {
            return;
        }

        Event::listen(
            CommandStarting::class,
            ConfigRepository::get('jaravel.console.listeners.started', ConsoleCommandStartedListener::class)
        );

        Event::listen(
            CommandFinished::class,
            ConfigRepository::get('jaravel.console.listeners.finished', ConsoleCommandFinishedListener::class)
        );
    }
}
