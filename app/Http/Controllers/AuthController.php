<?php

namespace App\Http\Controllers;

use App\Http\Requests\userLogin;
use App\Http\Requests\userSignUp;
use App\Http\Requests\ResetPassword;
use App\Http\Requests\UserVerification;
use Illuminate\Http\Request;
use App\User;
use App\AccessToken;
use App\config\Constants;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Support\Facades\Auth;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Exception;
use PHPUnit\TextUI\XmlConfiguration\Constant;

class AuthController extends Controller
{
/**
 * User : Registration with Validation Code.
 */
    public function register(userSignUp $request)
    {
        date_default_timezone_set("Asia/Karachi");
        try
        {
        DB::beginTransaction();
        $input = $request->all();
        $mytime = Carbon::now()->format('Y-m-d H:i:s');
        $email = $input['email'];
        $digits = 4;
        $verificationCode = rand(pow(10, $digits - 1), pow(10, $digits) - 1);
        $verificationExp = $mytime;

        $user = User::create([
                    'name'                =>    $request->name,
                    'email'               =>    $request->email,
                    'password'            =>    \Hash::make($request->password),
                    'status'              =>    Constants::USER_STATUS_UNVERIFIED,
                    'verification_code'   =>    $verificationCode,
                    'verification_expiry' =>    $verificationExp,
        ]);

        DB::commit();
        $sendmail = Mail::raw("Your user Registration Code is: $verificationCode", function ($message) use ($email) {
        $message->to($email)->subject('Account Verification Code - User Registration')->from(env('MAIL_FROM'));
        });

        return response()->json([
                    'success'   =>  true,
                    'status'    =>  200,
                    'message'   =>  'User created Successfully',
                    'data'      =>  $user
            ], 200);
        }
        catch(Exception $exception)
        {
            DB::rollback();
            return response()->json([
                    'success' => false,
                    'status'  => 500,
                    'message' => 'Internal Server Error',
                    'track'=> $exception->getMessage()
            ], 500);
        }
    }
/**
 * User Login Method Along with Constant file to change user Status.
 */
    public function login(userLogin $request)
    {
        DB::beginTransaction();
        try {
            $input = $request->all();
            if (!Auth::attempt(['email' => $input['email'], 'password' => $input['password']]))
            {
                return response()->json([
                    'success' => false,
                    'status' => 400,
                    'message' => 'Try Again Email Address/Password is Incorrect'
                ], 400);
            }
                     $user       = Auth::user();
                     $userId     = $user->id;
                     $userStatus = $user->status;
/**
 * Restricting Users via User Status from Constant File (config/Constant.php)
 */
            if ($userStatus == Constants::USER_STATUS_IN_ACTIVE) {
                return response()->json([
                    'success' => false,
                    'status' => 400,
                    'message' => 'User is Inactive'
                ], 400);
            }

            if ($userStatus == Constants::USER_STATUS_UNVERIFIED) {
                return response()->json([
                    'status' => 400,
                    'success' => false,
                    'message' => 'User is Unverified'
                ], 400);
            }
/**
 * Creating Token for Oauth_access_token ON LOGIN
*/
                    $accessTokenModel = new AccessToken();
                    $destroyToken = $accessTokenModel->sessionDestroyed($userId);
                    $token = $user->createToken('postscrud')->accessToken;
/**
 * User LOGIN Success Response
 */
            $login_time = Carbon::now()->format('Y-m-d H:i:s');


            $success = array(
                    'user_id'  => $userId,
                    'username' => $user->name,
                    'email'    => $user->email,
                    'status'   =>  $userStatus,
                    'token'    => "Bearer ".$token
            );
            DB::commit();

            return response()->json([
                    'success' => true,
                    'status' => 200,
                    'message' => 'logged in successfull',
                    'data' => $success
            ], 200);
        }

        catch (Exception $exception)
        {
            DB::rollback();
            return response()->json([
                    'success'     => false,
                    'status'      => 500,
                    'message'     => 'Sign in Failed',
                    'track_error' => $exception->getMessage()
            ], 500);
        }
    }
/**
 * User : Fetching User List from database/Viewing User list.
 */
    public function allUser()
    {
        try {
            $users = User::all();

            return response()->json([
                    "success" => true,
                    "status" => 200,
                    "message" => "Users fetched successfully",
                    "data" => $users
            ], 200);

        }
        catch (Exception $exception) {
            return response()->json([
                    "success" => false,
                    "status"  => 500,
                    "message" => $exception,
                    "track"   => $exception->getMessage()
            ], 500);
        }
    }
/**
 * Resetting Password via Verification Code
 **/
    public function RequestResetPass(ResetPassword $request)
    {
        DB::beginTransaction();
        date_default_timezone_set("Asia/Karachi");
        try
        {
            $user = new User();
            $input = $request->all();
            $email = $input['email'];
            $currentTime = Carbon::now();
            $user = User::where('email', $email)->first();

            //If User is not found
            if (!$user) {
                return response()->json([
                    "success" => false,
                    "status" => 400,
                    "message" => 'Please enter a valid Email Address'
                ], 400);
            }

            $user_id = $user->id;
            $digits = 4;

            $verificationCode = rand(pow(10, $digits - 1), pow(10, $digits) - 1);
            $verificationExp = $currentTime->addDays(7)->format('Y-m-d H:i:s');
            $resetPwData['verification_code'] = $verificationCode;
            $resetPwData['verification_expiry'] = $verificationExp;

            $data = $user->updateUser($user_id, $resetPwData);
            if (!$data) {
                return response()->json([
                    "success" => false,
                    "status" => 400,
                    "message" => 'Error generating verification code'
                ], 400);
            }
/**
 * Mailing | Generating Verification Code via E-mail
 **/
        Mail::raw("Your User Request Reset Code is: $verificationCode", function ($message) use ($email){
        $message->to($email)->subject('Account Verification Code - Password reset ')->from(env('MAIL_FROM'));
        });

            DB::commit();

            return response()->json([
                    "success" => true,
                    "status" => 200,
                    "message" => 'Verification Code Generated Successfully',
                    "data" => $data
                ], 200);
        }
        catch (Exception $exception)
        {
            DB::rollback();
            return response()->json([
                    "success" => false,
                    "status"  => 500,
                    "message" => 'Verification Code Fail to generate.',
                    "track"   => $exception->getMessage()
                ], 500);
        }
    }
/**
 * User Account Verification STATUS CHANGE METHOD
 */
    public function userAccountVerification(UserVerification $request)
    {
        DB::beginTransaction();
        try {
            $user = new User();
            $email = $request->input('email');
            $verifyCode = $request->input('verification_code');
            $current_time = Carbon::now()->format('Y-m-d H:i:s');

            $user = User::where('email', $email)->first();
            if (!$user) {
                return response()->json([
                    "success" => false,
                    "status" => 400,
                    "error" => 'Email does not exist'
                ], 400);
            }

            $user_code = $user->verification_code;
            if ($verifyCode != $user_code) {
                return response()->json([
                    "success" => false,
                    "status" => 400,
                    "message" => "Incorrect OTP"
                ], 400);
            }

            $user_id = $user->id;
            if($user->verification_expiry < $current_time)
            {
                return response()->json([
                    "success" => false,
                    "status" => 400,
                    "message" => "Verification Code has expired | Request a new One"
                ], 400);
            }

            else
            {
            $user->status = Constants::USER_STATUS_ACTIVE;
            $user->save();

            DB::commit();

            return response()->json([
                    "success" => true,
                    "status" => 200,
                    "message" => "User Status has been changed Successfully.",
                    "data" => $user
            ], 200);
            }

        }
        catch (Exception $exception)
        {
            DB::rollback();
            return response()->json([
                    "success" => false,
                    "status"  => 500,
                    "message" => "Internal Server Error",
                    "track"   => $exception->getMessage()
            ], 500);
        }
    }

    public function deleteUser($id)
    {
        try
        {
            $user = User::find($id);
            $user->delete();
            $user_id = $user->id;
            if(!$user_id)
            {
                return response()->json([
                    "success" => false,
                    "status"  => 404,
                    "message" => "User not found"
                ], 404);
            }

            if($id == null)
            {
                return response()->json([
                    "success" => false,
                    "status"  => 404,
                    "message" => "User id cannot be null"
                ], 404);
            }
            else
            {
            return response()->json([
                    "success" => true,
                    "status"  => 200,
                    "message" => "User has been deleted Successfully"
                ], 200);
            }
        }
        catch(Exception $exception)
        {
            return response()->json([
                    "success" => false,
                    "status"  => 500,
                    "message" => $exception,
                    "track"   => $exception->getMessage()
                ], 500);
        }
    }
}
