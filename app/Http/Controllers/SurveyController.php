<?php
namespace App\Http\Controllers;

use App\QualtricsSurveyProvider;
use App\Tracker\Messaging\SMSServiceInterface;
use App\Tracker\Survey\SurveyRepository;
use App\Tracker\User\User;
use App\Tracker\User\UserRepository;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use function PHPSTORM_META\map;

/**
 * Survey Controller
 *
 * Manages Surveys as a Resource
 *
 * @package App\Http\Controllers
 */
class SurveyController extends Controller
{

    /**
     * Store a newly created surveys
     *
     * @param  \Illuminate\Http\Request $request
     * @param UserRepository $user_repo
     * @param SurveyRepository $survey_repo
     * @param SMSServiceInterface $sms
     * @return Response
     * @throws \Exception if the POST failed
     */
    public function store(
        Request $request,
        UserRepository $user_repo,
        SurveyRepository $survey_repo,
        SMSServiceInterface $sms
    )
    {
        /* Turn the response into a usable array */
        $responses = $this->parseResponses($request->getContent());

        /* Build the Survey and User */
        try {
            $phone = $this->parsePhoneNumber($responses['phone']);
            $email = $responses['email'];
            $tenant = 1; // For now, we only have one tenant

            // Handle this in a transaction
            $user = \DB::transaction(function () use ($user_repo, $survey_repo, $responses, $phone, $email, $tenant) {
                $user = $user_repo->addIfNeeded($phone, $email, $tenant);
                $survey_repo->addFromSurveyResponses($responses, $user->id);

                return $user; // @todo: make sure this returns
            });

            /* Send the Response Link */
            // This is a temporary band-aid until we use a real service provider
            $message = $this->buildMessage($user->hash);
            $message = wordwrap($message, 70, "\r\n");
            mail($responses['email'], 'Personalized Status', $message);

            /* Return an All Clear to the API */
            return response($message, 200);

        } catch (\Exception $e) {
            \Log::warning("Post Failed for survey " . json_encode($responses));
            mail('chrismichaels84@gmail.com', 'Wrong', $e->getMessage()); // @todo: for now

            throw $e;
        }
    }

    /**
     * Creates the message to be sent to the user
     * @param $user_id
     * @return string
     */
    protected function buildMessage($user_id)
    {
        $site = \Config::get('sms.DASHBOARD_SITE_URL');
        return "Follow this link to your Recovery Status page: {$site}users/{$user_id}";
    }

    /**
     * Extracts all the non-number digits from the phone number
     * @param string $phone
     * @return string
     */
    protected function parsePhoneNumber ($phone) {
        preg_match_all('/\d+/', $phone, $matches);
        return implode("", $matches[0]);
    }

    /**
     * Cleans up the Survey Responses into a standard array
     * @param string $request
     * @return array
     */
    protected function parseResponses($request)
    {
        $provider = new QualtricsSurveyProvider();
        return $provider->parseRequest($request);
    }
}
