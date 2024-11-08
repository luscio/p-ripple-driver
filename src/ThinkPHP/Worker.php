<?php declare(strict_types=1);
/*
 * Copyright (c) 2023-2024.
 *
 * Permission is hereby granted, free of charge, to any person obtaining a copy
 * of this software and associated documentation files (the "Software"), to deal
 * in the Software without restriction, including without limitation the rights
 * to use, copy, modify, merge, publish, distribute, sublicense, and/or sell
 * copies of the Software, and to permit persons to whom the Software is
 * furnished to do so, subject to the following conditions:
 *
 * The above copyright notice and this permission notice shall be included in all
 * copies or substantial portions of the Software.
 *
 * THE SOFTWARE IS PROVIDED "AS IS", WITHOUT WARRANTY OF ANY KIND, EXPRESS OR
 * IMPLIED, INCLUDING BUT NOT LIMITED TO THE WARRANTIES OF MERCHANTABILITY,
 * FITNESS FOR A PARTICULAR PURPOSE AND NONINFRINGEMENT. IN NO EVENT SHALL THE
 * AUTHORS OR COPYRIGHT HOLDERS BE LIABLE FOR ANY CLAIM, DAMAGES OR OTHER
 * LIABILITY, WHETHER IN AN ACTION OF CONTRACT, TORT OR OTHERWISE, ARISING FROM,
 * OUT OF OR IN CONNECTION WITH THE SOFTWARE OR THE USE OR OTHER DEALINGS IN THE
 * SOFTWARE.
 *
 * 特此免费授予任何获得本软件及相关文档文件（“软件”）副本的人，不受限制地处理
 * 本软件，包括但不限于使用、复制、修改、合并、出版、发行、再许可和/或销售
 * 软件副本的权利，并允许向其提供本软件的人做出上述行为，但须符合以下条件：
 *
 * 上述版权声明和本许可声明应包含在本软件的所有副本或主要部分中。
 *
 * 本软件按“原样”提供，不提供任何形式的保证，无论是明示或暗示的，
 * 包括但不限于适销性、特定目的的适用性和非侵权性的保证。在任何情况下，
 * 无论是合同诉讼、侵权行为还是其他方面，作者或版权持有人均不对
 * 由于软件或软件的使用或其他交易而引起的任何索赔、损害或其他责任承担责任。
 */

namespace Ripple\Driver\ThinkPHP;

use Co\IO;
use Ripple\Driver\Utils\Config;
use Ripple\Driver\Utils\Console;
use Ripple\Driver\Utils\Guard;
use Ripple\Http\Server;
use Ripple\Http\Server\Request;
use Ripple\Kernel;
use Ripple\Worker\Manager;
use think\App;
use think\facade\Env;
use think\response\File;
use think\Route;
use Throwable;

use function app;
use function cli_set_process_title;
use function Co\repeat;
use function file_exists;
use function fwrite;
use function gc_collect_cycles;
use function getmypid;
use function posix_getpid;
use function root_path;
use function sprintf;
use function str_replace;
use function str_starts_with;
use function strtolower;
use function substr;

use const STDOUT;

/**
 * @Author cclilshy
 * @Date   2024/8/16 23:38
 */
class Worker extends \Ripple\Worker
{
    use Console;

    /**
     * @var Server
     */
    private Server $server;

    /**
     * @param string $address
     * @param int    $count
     */
    public function __construct(
        private readonly string $address = 'http://127.0.0.1:8008',
        int                     $count = 4
    ) {
        $this->count = $count;
        $this->name  = 'http-server';
    }

    /**
     * 注册服务流程,监听端口,发生于主进程中
     *
     * @Author cclilshy
     * @Date   2024/8/16 23:34
     *
     * @param Manager $manager
     *
     * @return void
     * @throws Throwable
     */
    public function register(Manager $manager): void
    {
        cli_set_process_title('think-guard');

        app()->bind(Worker::class, fn () => $this);

        $server = new Server($this->address, [
            'socket' => [
                'so_reuseport' => 1,
                'so_reuseaddr' => 1
            ]
        ]);

        $this->server = $server;

        fwrite(STDOUT, $this->formatRow(['Worker', $this->getName()]));
        fwrite(STDOUT, $this->formatRow(['Listen', $this->address]));
        fwrite(STDOUT, $this->formatRow(["Workers", $this->count]));
        fwrite(STDOUT, $this->formatRow(["- Logs"]));

        // 热重载监听文件改动
        if (Config::value2bool(Env::get('PHP_HOT_RELOAD'))) {
            $monitor = IO::File()->watch();
            $monitor->add(root_path() . '/app');
            $monitor->add(root_path() . '/config');
            $monitor->add(root_path() . '/extend');
            $monitor->add(root_path() . '/route');
            $monitor->add(root_path() . '/view');
            if (file_exists(root_path() . '/.env')) {
                $monitor->add(root_path() . '/.env');
            }

            Guard::relevance($manager, $this, $monitor);
        }
    }

    /**
     * 子进程启动流程,发生于子进程中
     *
     * @Author cclilshy
     * @Date   2024/8/17 11:08
     * @return void
     */
    public function boot(): void
    {
        cli_set_process_title('think-worker');

        fwrite(STDOUT, sprintf(
            "Worker %s@%d started.\n",
            $this->getName(),
            Kernel::getInstance()->supportProcessControl() ? posix_getpid() : getmypid()
        ));

        /*** register loop timer*/
        repeat(static function () {
            gc_collect_cycles();
        }, 1);

        $app = new App();
        $app->bind(Worker::class, fn () => $this);

        $this->server->onRequest(static function (
            Request $request
        ) use ($app) {
            $response = $request->getResponse();

            // Construct tp request object based on ripple/request meta information
            $headers = [];
            foreach ($request->SERVER as $key => $value) {
                if (str_starts_with($key, 'HTTP_')) {
                    $headers[strtolower(str_replace('_', '-', substr($key, 5)))] = $value;
                }
            }

            $thinkRequest = new \think\Request();
            $thinkRequest->setUrl($request->SERVER['REQUEST_URI']);
            $thinkRequest->setBaseUrl($request->SERVER['REQUEST_URI']);
            $thinkRequest->setHost($request->SERVER['HTTP_HOST'] ?? '');
            $thinkRequest->setPathinfo($request->SERVER['REQUEST_URI'] ?? '');
            $thinkRequest->setMethod($request->SERVER['REQUEST_METHOD'] ?? '');
            $thinkRequest->withHeader($headers);
            $thinkRequest->withCookie($request->COOKIE);
            $thinkRequest->withFiles($request->FILES);
            $thinkRequest->withGet($request->GET);
            $thinkRequest->withInput($request->CONTENT);

            # 交由think-app处理请求
            $thinkResponse = $app->http->run($thinkRequest);

            $response->setStatusCode($thinkResponse->getCode());
            foreach ($thinkResponse->getHeader() as $key => $value) {
                $response->withHeader($key, $value);
            }

            # 根据响应类型处理响应内容
            if ($thinkResponse instanceof File) {
                $response->setContent(IO::File()->open($thinkResponse->getData(), 'r+'));
            } else {
                $response->setContent($thinkResponse->getData());
            }
            $response->respond();
            $app->delete(Route::class);
        });
        $this->server->listen();
    }
}
