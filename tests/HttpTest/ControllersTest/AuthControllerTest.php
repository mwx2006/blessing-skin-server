<?php

namespace Tests;

use App\Events;
use App\Mail\ForgotPassword;
use App\Models\Player;
use App\Models\User;
use App\Rules\Captcha;
use App\Services\Facades\Option;
use Cache;
use Event;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;
use Laravel\Socialite\AbstractUser;
use Laravel\Socialite\Facades\Socialite;
use Tests\Fakes\Filter;
use Vectorface\Whip\Whip;

class AuthControllerTest extends TestCase
{
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();
        app()->instance(Captcha::class, new class() extends Captcha {
            public function passes($attribute, $value)
            {
                return true;
            }
        });
    }

    public function testLogin()
    {
        $this->get('/auth/login')->assertSee('Log in');

        option(['recaptcha_sitekey' => 'key']);
        $this->get('/auth/login')->assertSee('recaptcha.net');
    }

    public function testHandleLogin()
    {
        Event::fake();

        $user = factory(User::class)->create();
        $user->changePassword('12345678');
        $player = factory(Player::class)->create(['uid' => $user->uid]);

        // Should return a warning if `identification` is empty
        $this->postJson('/auth/login')->assertJsonValidationErrors('identification');

        // Should return a warning if `password` is empty
        $this->postJson(
            '/auth/login', ['identification' => $user->email]
        )->assertJsonValidationErrors('password');

        // Should return a warning if length of `password` is lower than 6
        $this->postJson(
            '/auth/login', [
            'identification' => $user->email,
            'password' => '123',
        ])->assertJsonValidationErrors('password');

        // Should return a warning if length of `password` is greater than 32
        $this->postJson(
            '/auth/login', [
            'identification' => $user->email,
            'password' => Str::random(80),
        ])->assertJsonValidationErrors('password');

        $this->flushSession();

        // Should return a warning if user isn't existed
        $this->postJson(
            '/auth/login', [
            'identification' => 'nope@nope.net',
            'password' => '12345678',
        ])->assertJson([
            'code' => 2,
            'message' => trans('auth.validation.user'),
        ]);
        Event::assertDispatched('auth.login.attempt', function ($event, $payload) use ($user) {
            $this->assertEquals('nope@nope.net', $payload[0]);
            $this->assertEquals('12345678', $payload[1]);
            $this->assertEquals('email', $payload[2]);

            return true;
        });
        Event::assertNotDispatched('auth.login.ready');
        Event::assertNotDispatched('auth.login.succeeded');
        Event::assertNotDispatched('auth.login.failed');
        $this->flushSession();

        Event::fake();
        $filter = Filter::fake();
        $whip = new Whip();
        $ip = $whip->getValidIpAddress();
        $loginFailsCacheKey = sha1('login_fails_'.$ip);

        // Logging in should be failed if password is wrong
        $this->postJson(
            '/auth/login', [
            'identification' => $user->email,
            'password' => 'wrong-password',
        ])->assertJson(
            [
                'code' => 1,
                'message' => trans('auth.validation.password'),
                'data' => ['login_fails' => 1],
            ]
        );
        $filter->assertApplied('client_ip', function ($value) use ($ip) {
            $this->assertEquals($ip, $value);

            return true;
        });
        $this->assertTrue(Cache::has($loginFailsCacheKey));
        Event::assertDispatched('auth.login.attempt', function ($event, $payload) use ($user) {
            $this->assertEquals($user->email, $payload[0]);
            $this->assertEquals('wrong-password', $payload[1]);
            $this->assertEquals('email', $payload[2]);

            return true;
        });
        Event::assertDispatched('auth.login.ready', function ($event, $payload) use ($user) {
            $this->assertEquals($user->uid, $payload[0]->uid);

            return true;
        });
        Event::assertDispatched('auth.login.failed', function ($event, $payload) use ($user) {
            $this->assertEquals($user->uid, $payload[0]->uid);
            $this->assertEquals(1, $payload[1]);

            return true;
        });

        $this->flushSession();

        // Should check captcha if there are too many fails
        Cache::put($loginFailsCacheKey, 4);
        $this->postJson(
                '/auth/login', [
                'identification' => $user->email,
                'password' => '12345678',
            ])->assertJsonValidationErrors('captcha');

        Cache::flush();
        $this->flushSession();

        // Should clean the `login_fails` session if logged in successfully
        Cache::put($loginFailsCacheKey, 1);
        $this->postJson('/auth/login', [
            'identification' => $user->email,
            'password' => '12345678',
        ])->assertJson(
            [
                'code' => 0,
                'message' => trans('auth.login.success'),
            ]
        );
        $this->assertFalse(Cache::has($loginFailsCacheKey));
        Event::assertDispatched('auth.login.ready', function ($event, $payload) use ($user) {
            $this->assertEquals($user->uid, $payload[0]->uid);

            return true;
        });
        Event::assertDispatched('auth.login.succeeded', function ($event, $payload) use ($user) {
            $this->assertEquals($user->uid, $payload[0]->uid);

            return true;
        });

        Event::assertDispatched(Events\UserTryToLogin::class);
        Event::assertDispatched(Events\UserLoggedIn::class);

        Cache::flush();
        $this->flushSession();

        // Logged in should be in success if logged in with player name
        auth()->logout();
        $this->postJson(
            '/auth/login', [
            'identification' => $player->name,
            'password' => '12345678',
        ]
        )->assertJson(
            [
                'code' => 0,
                'message' => trans('auth.login.success'),
            ]
        );
        $this->assertAuthenticated();
    }

    public function testLogout()
    {
        Event::fake();

        $user = factory(User::class)->create();
        $this->actingAs($user)->postJson('/auth/logout')->assertJson(
            [
                'code' => 0,
                'message' => trans('auth.logout.success'),
            ]
        );
        $this->assertGuest();
        Event::assertDispatched('auth.logout.before', function ($event, $payload) use ($user) {
            $this->assertEquals($user->uid, $payload[0]->uid);

            return true;
        });
        Event::assertDispatched('auth.logout.after', function ($event, $payload) use ($user) {
            $this->assertEquals($user->uid, $payload[0]->uid);

            return true;
        });
    }

    public function testRegister()
    {
        $this->get('/auth/register')->assertSee('Register');

        option(['user_can_register' => false]);
        $this->get('/auth/register')->assertSee(trans('auth.register.close'));
    }

    public function testHandleRegister()
    {
        Event::fake();
        $filter = Filter::fake();
        $whip = new Whip();
        $ip = $whip->getValidIpAddress();

        // Should return a warning if `email` is empty
        $this->postJson('/auth/register')->assertJsonValidationErrors('email');

        // Should return a warning if `email` is invalid
        $this->postJson(
            '/auth/register',
            ['email' => 'not_an_email']
        )->assertJsonValidationErrors('email');

        // An existed user
        $existedUser = factory(User::class)->create();
        $this->postJson(
            '/auth/register',
            ['email' => $existedUser->email]
        )->assertJsonValidationErrors('email');

        // Should return a warning if `password` is empty
        $this->postJson(
            '/auth/register',
            ['email' => 'a@b.c']
        )->assertJsonValidationErrors('password');

        // Should return a warning if length of `password` is lower than 8
        $this->postJson(
            '/auth/register',
            [
                'email' => 'a@b.c',
                'password' => '1',
            ]
        )->assertJsonValidationErrors('password');

        // Should return a warning if length of `password` is greater than 32
        $this->postJson(
            '/auth/register',
            [
                'email' => 'a@b.c',
                'password' => Str::random(33),
            ]
        )->assertJsonValidationErrors('password');

        // The register_with_player_name option is set to true by default.
        // Should return a warning if `player_name` is empty
        $this->postJson(
            '/auth/register',
            [
                'email' => 'a@b.c',
                'password' => '12345678',
                'captcha' => 'a',
            ]
        )->assertJsonValidationErrors('player_name');

        // Should return a warning if `player_name` is invalid
        option(['player_name_rule' => 'official']);
        $this->postJson(
            '/auth/register',
            [
                'email' => 'a@b.c',
                'password' => '12345678',
                'player_name' => '角色名',
                'captcha' => 'a',
            ]
        )->assertJsonValidationErrors('player_name');

        // Should return a warning if `player_name` is too long
        $this->postJson(
            '/auth/register',
            [
                'email' => 'a@b.c',
                'password' => '12345678',
                'player_name' => Str::random(option('player_name_length_max') + 10),
                'captcha' => 'a',
            ]
        )->assertJsonValidationErrors('player_name');

        // Existed player
        $player = factory(Player::class)->create();
        $this->postJson(
            '/auth/register',
            [
                'email' => 'a@b.c',
                'password' => '12345678',
                'player_name' => $player->name,
                'captcha' => 'a',
            ]
        )->assertJson([
            'code' => 2,
            'message' => trans('user.player.add.repeated'),
        ]);
        $this->assertNull(User::where('email', 'a@b.c')->first());
        Event::assertDispatched('auth.registration.attempt', function ($event, $payload) {
            [$data] = $payload;
            $this->assertEquals('a@b.c', $data['email']);
            $this->assertEquals('12345678', $data['password']);

            return true;
        });
        Event::assertNotDispatched('auth.registration.ready');
        Event::assertNotDispatched('auth.registration.completed');

        option(['register_with_player_name' => false]);

        // Should return a warning if `nickname` is empty
        $this->postJson(
            '/auth/register',
            [
                'email' => 'a@b.c',
                'password' => '12345678',
                'captcha' => 'a',
            ]
        )->assertJsonValidationErrors('nickname');

        // Should return a warning if `nickname` is too long
        $this->postJson(
            '/auth/register',
            [
                'email' => 'a@b.c',
                'password' => '12345678',
                'nickname' => Str::random(256),
                'captcha' => 'a',
            ]
        )->assertJsonValidationErrors('nickname');

        // Should return a warning if `captcha` is empty
        $this->postJson(
            '/auth/register',
            [
                'email' => 'a@b.c',
                'password' => '12345678',
                'nickname' => 'nickname',
            ]
        )->assertJsonValidationErrors('captcha');

        // Should be forbidden if registering is closed
        Option::set('user_can_register', false);
        $this->postJson(
            '/auth/register',
            [
                'email' => 'a@b.c',
                'password' => '12345678',
                'nickname' => 'nickname',
                'captcha' => 'a',
            ]
        )->assertJson([
            'code' => 7,
            'message' => trans('auth.register.close'),
        ]);

        option(['user_can_register' => true, 'regs_per_ip' => -1]);
        $this->postJson(
            '/auth/register',
            [
                'email' => 'a@b.c',
                'password' => '12345678',
                'nickname' => 'nickname',
                'captcha' => 'a',
            ]
        )->assertJson([
            'code' => 7,
            'message' => trans('auth.register.max', ['regs' => option('regs_per_ip')]),
        ]);

        Option::set('regs_per_ip', 100);

        // Database should be updated if succeeded
        $response = $this->postJson(
            '/auth/register',
            [
                'email' => 'a@b.c',
                'password' => '12345678',
                'nickname' => 'nickname',
                'captcha' => 'a',
            ]
        );
        $newUser = User::where('email', 'a@b.c')->first();
        $response->assertJson([
            'code' => 0,
            'message' => trans('auth.register.success'),
        ]);
        $filter->assertApplied('client_ip', function ($value) use ($ip) {
            $this->assertEquals($ip, $value);

            return true;
        });
        $this->assertTrue($newUser->verifyPassword('12345678'));
        $this->assertDatabaseHas('users', [
            'email' => 'a@b.c',
            'nickname' => 'nickname',
            'score' => option('user_initial_score'),
            'ip' => $ip,
            'permission' => User::NORMAL,
        ]);
        $this->assertAuthenticated();
        Event::assertDispatched('auth.registration.attempt', function ($event, $payload) {
            [$data] = $payload;
            $this->assertEquals('a@b.c', $data['email']);
            $this->assertEquals('12345678', $data['password']);

            return true;
        });
        Event::assertDispatched('auth.registration.ready', function ($event, $payload) {
            [$data] = $payload;
            $this->assertEquals('a@b.c', $data['email']);
            $this->assertEquals('12345678', $data['password']);

            return true;
        });
        Event::assertDispatched('auth.registration.completed', function ($event, $payload) {
            [$user] = $payload;
            $this->assertEquals('a@b.c', $user->email);
            $this->assertGreaterThan(0, $user->uid);

            return true;
        });
        Event::assertDispatched(Events\UserRegistered::class);
        Event::assertDispatched('auth.login.ready', function ($event, $payload) {
            [$user] = $payload;
            $this->assertEquals('a@b.c', $user->email);

            return true;
        });
        Event::assertDispatched('auth.login.succeeded', function ($event, $payload) {
            [$user] = $payload;
            $this->assertEquals('a@b.c', $user->email);

            return true;
        });

        // Require player name
        option(['register_with_player_name' => true]);
        auth()->logout();
        $this->postJson(
            '/auth/register',
            [
                'email' => 'abc@test.org',
                'password' => '12345678',
                'player_name' => 'name',
                'captcha' => 'a',
            ]
        )->assertJson(['code' => 0]);
        $this->assertNotNull(Player::where('player', 'name'));
    }

    public function testForgot()
    {
        $this->get('/auth/forgot')->assertSee('Forgot Password');

        config(['mail.default' => '']);
        $this->get('/auth/forgot')->assertSee(trans('auth.forgot.disabled'));
    }

    public function testHandleForgot()
    {
        Event::fake();
        Mail::fake();
        $filter = Filter::fake();

        // Should be forbidden if "forgot password" is closed
        config(['mail.default' => '']);
        $this->postJson('/auth/forgot', [
            'email' => 'nope@nope.net',
            'captcha' => 'a',
        ])->assertJson([
            'code' => 1,
            'message' => trans('auth.forgot.disabled'),
        ]);
        config(['mail.default' => 'smtp']);

        $whip = new Whip();
        $ip = $whip->getValidIpAddress();
        $lastMailCacheKey = sha1('last_mail_'.$ip);

        // Should be forbidden if sending email frequently
        Cache::put($lastMailCacheKey, time());
        $this->postJson('/auth/forgot', [
            'email' => 'nope@nope.net',
            'captcha' => 'a',
        ])->assertJson([
            'code' => 2,
            'message' => trans('auth.forgot.frequent-mail'),
        ]);
        $filter->assertApplied('client_ip', function ($value) use ($ip) {
            $this->assertEquals($ip, $value);

            return true;
        });
        Event::assertDispatched('auth.forgot.attempt', function ($event, $payload) {
            $this->assertEquals('nope@nope.net', $payload[0]);

            return true;
        });
        Event::assertNotDispatched('auth.forgot.ready');
        Event::assertNotDispatched('auth.forgot.sent');
        Event::assertNotDispatched('auth.forgot.sent');
        Cache::flush();
        $this->flushSession();

        // Should return a warning if user is not existed
        $user = factory(User::class)->create();
        $this->withSession(['phrase' => 'a'])->postJson('/auth/forgot', [
            'email' => 'nope@nope.net',
            'captcha' => 'a',
        ])->assertJson([
            'code' => 1,
            'message' => trans('auth.forgot.unregistered'),
        ]);

        Event::fake();
        $this->postJson('/auth/forgot', [
            'email' => $user->email,
            'captcha' => 'a',
        ])->assertJson([
            'code' => 0,
            'message' => trans('auth.forgot.success'),
        ]);
        $this->assertTrue(Cache::has($lastMailCacheKey));
        Cache::flush();
        Event::assertDispatched('auth.forgot.attempt', function ($event, $payload) use ($user) {
            $this->assertEquals($user->email, $payload[0]);

            return true;
        });
        Event::assertDispatched('auth.forgot.ready', function ($event, $payload) use ($user) {
            $this->assertEquals($user->email, $payload[0]->email);

            return true;
        });
        Mail::assertSent(ForgotPassword::class, function ($mail) use ($user) {
            return $mail->hasTo($user->email);
        });
        Event::assertDispatched('auth.forgot.sent', function ($event, $payload) use ($user) {
            $this->assertEquals($user->email, $payload[0]->email);
            $this->assertStringContainsString('auth/reset/'.$user->uid, $payload[1]);

            return true;
        });

        // Should handle exception when sending email
        Event::fake();
        Mail::shouldReceive('to')
            ->once()
            ->andThrow(new \Mockery\Exception('A fake exception.'));
        $this->flushSession();
        $this->withSession(['phrase' => 'a'])
            ->postJson('/auth/forgot', [
                'email' => $user->email,
                'captcha' => 'a',
            ])->assertJson([
                'code' => 2,
                'message' => trans('auth.forgot.failed', ['msg' => 'A fake exception.']),
            ]);
        Event::assertNotDispatched('auth.forgot.sent');
        Event::assertDispatched('auth.forgot.failed', function ($event, $payload) use ($user) {
            $this->assertEquals($user->email, $payload[0]->email);
            $this->assertStringContainsString('auth/reset/'.$user->uid, $payload[1]);

            return true;
        });

        // Addition: Mailable test
        $site_name = option_localized('site_name');
        $mailable = new ForgotPassword('url');
        $mailable->build();
        $this->assertTrue($mailable->hasFrom(config('mail.from.address')));
        $this->assertEquals(trans('auth.forgot.mail.title', ['sitename' => $site_name]), $mailable->subject);
        $this->assertEquals('mails.password-reset', $mailable->view);
    }

    public function testReset()
    {
        $user = factory(User::class)->create();

        $this->get(
            URL::temporarySignedRoute('auth.reset', now()->addHour(), ['uid' => $user->uid])
        )->assertSuccessful();
    }

    public function testHandleReset()
    {
        Event::fake();

        $user = factory(User::class)->create();
        $url = URL::temporarySignedRoute('auth.reset', now()->addHour(), ['uid' => $user->uid]);

        // Should return a warning if `password` is empty
        $this->postJson($url)->assertJsonValidationErrors('password');

        // Should return a warning if `password` is too short
        $this->postJson($url, ['password' => '123'])
            ->assertJsonValidationErrors('password');

        // Should return a warning if `password` is too long
        $this->postJson($url, ['password' => Str::random(33)])
            ->assertJsonValidationErrors('password');

        // Success
        $this->postJson($url, ['password' => '12345678'])->assertJson([
            'code' => 0,
            'message' => trans('auth.reset.success'),
        ]);
        $user->refresh();
        $this->assertTrue($user->verifyPassword('12345678'));
        Event::assertDispatched('auth.reset.before', function ($event, $payload) use ($user) {
            $this->assertEquals($user->uid, $payload[0]->uid);
            $this->assertEquals('12345678', $payload[1]);

            return true;
        });
        Event::assertDispatched('auth.reset.after', function ($event, $payload) use ($user) {
            $this->assertEquals($user->uid, $payload[0]->uid);
            $this->assertEquals('12345678', $payload[1]);

            return true;
        });
    }

    public function testCaptcha()
    {
        $this->mock(\Gregwar\Captcha\CaptchaBuilder::class, function ($mock) {
            $mock->shouldReceive('build')->with(100, 34)->once();
            $mock->shouldReceive('getPhrase')->once()->andReturn('くみこ');
            $mock->shouldReceive('output')->once()->andReturn('');
        });
        $this->get('/auth/captcha')
            ->assertSuccessful()
            ->assertHeader('Content-Type', 'image/jpeg')
            ->assertHeader('Cache-Control', 'no-store, private')
            ->assertSessionHas('captcha', 'くみこ');
    }

    public function testFillEmail()
    {
        $user = factory(User::class)->create(['email' => '']);
        $other = factory(User::class)->create();
        $this->actingAs($user)->post('/auth/bind')->assertRedirect('/');
        $this->actingAs($user)->post('/auth/bind', ['email' => 'a'])->assertRedirect('/');
        $this->actingAs($user)->post('/auth/bind', ['email' => $other->email])->assertRedirect('/');

        $this->actingAs($user)->post('/auth/bind', ['email' => 'a@b.c'])->assertRedirect('/user');
        $user->refresh();
        $this->assertEquals('a@b.c', $user->email);
    }

    public function testVerify()
    {
        $url = URL::signedRoute('auth.verify', ['uid' => 1]);

        // Should be forbidden if account verification is disabled
        option(['require_verification' => false]);
        $this->get($url)->assertSee(trans('user.verification.disabled'));
        option(['require_verification' => true]);

        $this->get($url)->assertSee(trans('auth.verify.invalid'));

        $user = factory(User::class)->create();
        $url = URL::signedRoute('auth.verify', ['uid' => $user->uid]);
        $this->get($url)->assertSee(trans('auth.verify.invalid'));

        $user = factory(User::class)->create(['verified' => false]);
        $url = URL::signedRoute('auth.verify', ['uid' => $user->uid]);
        $this->get($url)->assertViewIs('auth.verify');
        $this->assertEquals(1, User::find($user->uid)->verified);
    }

    public function testApiLogin()
    {
        $user = factory(User::class)->create();
        $user->changePassword('12345678');

        $this->postJson('/api/auth/login')->assertJson(['token' => false]);
        $token = $this->postJson('/api/auth/login', [
            'email' => $user->email,
            'password' => '12345678',
        ])->decodeResponseJson('token');
        $this->assertTrue(is_string($token));

        $this->postJson('/api/auth/login', [
            'email' => $user->email,
            'password' => '123456789',
        ])->assertJson(['token' => '']);
    }

    public function testApiLogout()
    {
        $user = factory(User::class)->create();
        $user->changePassword('12345678');
        $token = $this->postJson('/api/auth/login', [
            'email' => $user->email,
            'password' => '12345678',
        ])->decodeResponseJson('token');

        $this->post('/api/auth/logout', [], [
            'Authorization' => "Bearer $token",
        ])->assertNoContent();
    }

    public function testApiRefresh()
    {
        $user = factory(User::class)->create();
        $user->changePassword('12345678');
        $token = $this->postJson('/api/auth/login', [
            'email' => $user->email,
            'password' => '12345678',
        ])->decodeResponseJson('token');

        $token = $this->postJson('/api/auth/refresh', [], [
            'Authorization' => "Bearer $token",
        ])->decodeResponseJson('token');
        $this->assertTrue(is_string($token));
    }

    public function testOauthLogin()
    {
        Socialite::shouldReceive('driver')
            ->with('github')
            ->once()
            ->andReturn(new class() {
                public function redirect()
                {
                    return redirect('/');
                }
            });

        $this->get('/auth/login/github')->assertRedirect();
    }

    public function testOauthCallback()
    {
        Event::fake();
        $filter = Filter::fake();
        $whip = new Whip();
        $ip = $whip->getValidIpAddress();

        Socialite::shouldReceive('driver')
            ->with('github')
            ->times(3)
            ->andReturn(
                new class() {
                    public function user()
                    {
                        return new class() extends AbstractUser {
                        };
                    }
                },
                new class() {
                    public function user()
                    {
                        return new class() extends AbstractUser {
                            public $email = 'a@b.c';

                            public $nickname = 'abc';
                        };
                    }
                },
                new class() {
                    public function user()
                    {
                        return new class() extends AbstractUser {
                            public $email = 'a@b.c';

                            public $nickname = 'abc';
                        };
                    }
                }
            );

        $this->get('/auth/login/github/callback')
            ->assertStatus(500)
            ->assertSee('Unsupported');

        $this->get('/auth/login/github/callback')->assertRedirect('/user');
        $filter->assertApplied('client_ip', function ($value) use ($ip) {
            $this->assertEquals($ip, $value);

            return true;
        });
        $this->assertDatabaseHas('users', [
            'email' => 'a@b.c',
            'nickname' => 'abc',
            'score' => option('user_initial_score'),
            'avatar' => 0,
            'ip' => $ip,
            'permission' => User::NORMAL,
            'verified' => true,
        ]);
        $this->assertAuthenticated();
        Event::assertDispatched('auth.registration.completed', function ($event, $payload) {
            [$user] = $payload;
            $this->assertEquals('a@b.c', $user->email);
            $this->assertEquals(1, $user->uid);

            return true;
        });
        Event::assertDispatched('auth.login.ready', function ($event, $payload) {
            [$user] = $payload;
            $this->assertEquals('a@b.c', $user->email);

            return true;
        });
        Event::assertDispatched('auth.login.succeeded', function ($event, $payload) {
            [$user] = $payload;
            $this->assertEquals('a@b.c', $user->email);

            return true;
        });

        auth()->logout();
        $this->assertGuest();
        Event::fake();

        $this->get('/auth/login/github/callback')->assertRedirect('/user');
        $this->assertAuthenticated();
        Event::assertNotDispatched('auth.registration.completed');
        Event::assertDispatched('auth.login.ready', function ($event, $payload) {
            [$user] = $payload;
            $this->assertEquals('a@b.c', $user->email);

            return true;
        });
        Event::assertDispatched('auth.login.succeeded', function ($event, $payload) {
            [$user] = $payload;
            $this->assertEquals('a@b.c', $user->email);

            return true;
        });
    }
}
