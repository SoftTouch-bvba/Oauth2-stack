<?php

namespace Cloudoki\OaStack\Controllers;

use OAuth2\Response;
use Carbon\Carbon;
use Illuminate\Routing\Controller;
use Cloudoki\InvalidParameterException;
use Cloudoki\OaStack;
use Illuminate\Support\Facades\Input;
use Illuminate\Support\Facades\Redirect;
use Illuminate\Support\Facades\Config;
use \Cloudoki\OaStack\Models\User;
use \Cloudoki\OaStack\Models\Account;
class OAuth2Controller extends Controller {

	/**
	 *	User Login
	 *	Based on simple form.
	 *
	 *	@param	string	$payload
	 *
	 *	@return mixed
	 */
	public function login ($payload)
	{
		# Add payload to GET
		$_GET = (array) $payload;
		# Validate client
		$server = Oauth2Verifier::getInstance ()->getServer ();
		$request = Oauth2Verifier::getInstance ()->getRequest ();
		$response = new Response();

		$client = self::validateClient ($server, $request, $response);
		if (!$client || $client->getRedirectUri () != $payload->redirect_uri) {
			throw new \Cloudoki\InvalidParameterException ('Invalid client id or redirect uri');

		}
		# Validate user
		if (!empty($payload->email)) {
			$user = User::email ($payload->email)->first ();
		} else {
			throw new \Cloudoki\InvalidParameterException ('Invalid e-mail.');
		}

		if (isset($user) && !$user || !$user->checkPassword ($payload->password)) {
			throw new \Cloudoki\InvalidParameterException ('Invalid password or e-mail.');
		}
		# Validate Authorization
		$authorization = $user->oauth2authorizations ()->where ('client_id', $client->getClientId ())->first ();

		if (!$authorization)
		{
			$sessiontoken = Oauth2AccessToken::create (
				[
					'access_token'=> Oauth2AccessToken::generateAccessToken(),
					'client_id'=> $client->getClientId (),
					'user_id'=> $user->id,
					'expires'=> new Carbon('+ 2 minute', Config::get ('app.timezone'))
				]);
			return
				[
					'view'=> 'approve',
					'session_token'=> $sessiontoken->getToken (),
					'user'=> $user,
					'client'=> $client
				];
		}


		# Or return validated
		$accesstoken =
			$user->oauth2accesstokens ()->validWithClient ($client)->first ()?:
				Oauth2AccessToken::create (
					[
						'access_token'=> Oauth2AccessToken::generateAccessToken(),
						'client_id'=> $client->getClientId (),
						'user_id'=> $user->getId (),
						'expires'=> Carbon::now(new DateTimeZone(Config::get ('app.timezone')))->addYear ()
					]);

		return
			[
				'uri'=> $client->getRedirectUri () . '?access_token=' . $accesstoken->getToken ()
			];
	}



	/**
	 *	User Login
	 *	Based on simple form.
	 *
	 *	@param	string	$payload
	 *
	 *	@return mixed
	 */
	public static function authorize ($payload)
	{

		# Validate session token
		$sessiontoken = Oauth2AccessToken::whereAccessToken ($payload->session_token)->valid ()->first ();


		if (!$sessiontoken || $sessiontoken->user->getKey () != (int) $payload->approve)

			throw new \Cloudoki\InvalidParameterException ('Session expired or invalid approval.');


		# Token handling
		Oauth2Authorization::create (['client_id'=> $sessiontoken->client->getClientId (), 'user_id'=> $sessiontoken->user->getId (), 'authorization_date'=> Carbon::now(new DateTimeZone(Config::get ('app.timezone')))]);

		$accesstoken = Oauth2AccessToken::create (
			[
				'access_token'=> Oauth2AccessToken::generateAccessToken(),
				'client_id'=> $sessiontoken->client->getClientId (),
				'user_id'=> $sessiontoken->user->getId (),
				'expires'=> Carbon::now(new DateTimeZone(Config::get ('app.timezone')))->addYear ()
			]);

		$sessiontoken->delete ();

		return
			[
				'uri'=> $sessiontoken->client->getRedirectUri ($accesstoken->getToken ())
			];
	}


	/**
	 *	Validate Client
	 *	Based on Oauth2 package. Throw error if not valid
	 */
	public static function validateClient ($server, $request, $response)
	{
		if (!$server->validateAuthorizeRequest($request, $response))

			return false;

		$clientid = $server->getAuthorizeController ()->getClientId ();

		return Oauth2Client::whereClientId ($clientid)->first ();
	}


	/**
	 *	Invite user
	 *	Based on simple form.
	 *
	 *	@param	User	$user
	 *	@param	Account	$account
	 *	@param	string	$invitation_token
	 */
	public static function invite ($user, $account, $invitation_token)
	{
		$data =
			[
				'url'=> Config::get ('oastack::invite_url') . '/' . $invitation_token,
				'account'=> $account->getName ()
			];

		Mail::send ('oastack::emails.invitation', $data, function ($message) use ($user)
		{
			$message->to ($user->getEmail (), $user->getFullName ())
				->subject (trans ('oastack::emails.invite.subject'));

		});
	}

	/**
	 *	Accounts list
	 *	For API side invite usage.
	 *
	 *	@return	array
	 */
	public static function accounts ()
	{
		// Bouncer here

		return [];
	}


	/**
	 *	Reset user password
	 *	Based on simple form.
	 *
	 *	@param	User	$user
	 *	@param	Account	$account
	 *	@param	string	$invitation_token
	 */
	public static function resetpassword ($payload)
	{
		$user = User::email ($payload->email)->first ();

		if (!$user)

			throw new \Cloudoki\InvalidParameterException ('Unknown e-mail address.');

		# Reset token
		$reset_token = $user->makeToken ();
		$user->setResetToken ($reset_token)->save ();

		$data =
			[
				'url'=> Config::get ('oastack::reset_url') . '/' . $reset_token,
				'user'=> $user
			];

		Mail::send ('oastack::emails.reset', $data, function ($message) use ($user)
		{
			$message->to ($user->getEmail (), $user->getFullName ())
				->subject (trans ('oastack::emails.reset.subject'));

		});
	}


	/**
	 *	Change user password
	 *	Based on simple form.
	 *
	 *	@param	array	$payload
	 */
	public static function changepassword ($payload)
	{
		$user = User::email ($payload->email)->resetToken ($payload->reset_token)->first ();

		# e-mail and token validation
		if (!$user)

			throw new \Cloudoki\InvalidParameterException ('Invalid e-mail address or reset link.');

		# repeated password validation
		if ($payload->password !== $payload->password_confirmation)

			throw new \Cloudoki\InvalidParameterException ('The passwords do not match.');


		# Update user
		$user->setPassword ($payload->password)
			->setResetToken (null)
			->save ();
	}


	/**
	 *	Invitation Token
	 *	Get related models.
	 *
	 *	@param	string	$token
	 */
	public static function withinvitation ($token)
	{
		# Return user and account
		return
			[
				User::whereHas ('accounts', function ($q) use ($token) { $q->where ('account_user.invitation_token', $token); })->first (),
				Account::whereHas ('users', function ($q) use ($token) { $q->where ('account_user.invitation_token', $token); })->first ()
			];
	}


	/**
	 *	Ratify Token
	 *	Based on MD5 string.
	 *
	 *	@param	object	$payload
	 */
	public function identifyinvite ($payload)
	{
		# Get User by Invitation token
		list ($user, $account) = self::withinvitation ($payload->token);

		if (!$user)

			throw new \Cloudoki\nvalidParameterException ('There is something wrong with that token.');

		else return
			[
				'user'=>  $user->schema ('full'),
				'account'=>  $account->schema ('basic')
			];
	}


	/**
	 *	Subscribe
	 *	Based on simple form.
	 *
	 *	@param	object	$payload
	 */
	public function subscribe ($payload)
	{
		# Token-based query
		list ($user, $account) = self::withinvitation ($payload->token);

		if (!isset ($user, $account))

			throw new \Cloudoki\InvalidParameterException ('There is something wrong with the invitation token.');

		# Activate user
		$user->setFirstname ($payload->firstname)
			->setLastname ($payload->lastname)
			->setEmail ($payload->email)
			->setPassword ($payload->password)
			->save ();

		return $account->schema ('basic');
	}


	/**
	 *	Register user
	 *	Based on simple form.
	 */
	public function registeruser ()
	{
		// Will throw 403 if not valid
		// OaStack::check ();

		$client = new Oauth2Client ();
		$client ->appendPayload (json_decode (Input::get ('payload')))
			->save ();

		return $client->schema ('full');
	}


	/**
	 *  Register Client in database and application
	 *	Register app
	 *	Based on simple form.
	 */
	public function registerclient ($payload = [])
	{
		// Will throw 403 if not valid
		//\Cloudoki\OaStack\OaStack::check ();
		$payload = $payload ? $payload : Input::get ('payload');
		$client = new Oauth2Client();
		$client->appendPayload ($payload)
			->save();

		return $client->schema ('basic');
	}
}


