<?php

namespace DTApi\Repository;

use DTApi\Events\SessionEnded;
use DTApi\Helpers\SendSMSHelper;
use Event;
use Carbon\Carbon;
use Monolog\Logger;
use DTApi\Models\Job;
use DTApi\Models\User;
use DTApi\Models\Language;
use DTApi\Models\UserMeta;
use DTApi\Helpers\TeHelper;
use Illuminate\Http\Request;
use DTApi\Models\Translator;
use DTApi\Mailers\AppMailer;
use DTApi\Models\UserLanguages;
use DTApi\Events\JobWasCreated;
use DTApi\Events\JobWasCanceled;
use DTApi\Models\UsersBlacklist;
use DTApi\Helpers\DateTimeHelper;
use DTApi\Mailers\MailerInterface;
use Illuminate\Support\Facades\DB;
use Monolog\Handler\StreamHandler;
use Illuminate\Support\Facades\Log;
use Monolog\Handler\FirePHPHandler;
use Illuminate\Support\Facades\Auth;
use App\Services\NotificationService;
/**
 * Class BookingRepository
 * @package DTApi\Repository
 */
class BookingRepository extends BaseRepository
{

    protected $model;
    protected $mailer;
    protected $logger;
    protected $notificationService;
    /**
     * @param Job $model
     */
    public function __construct(Job $model, MailerInterface $mailer, NotificationService $notificationService)
    {
        parent::__construct($model);
        $this->mailer = $mailer;
        $this->notificationService = $notificationService;
        $this->logger = new Logger('admin_logger');
        $this->logger->pushHandler(new StreamHandler(storage_path('logs/admin/laravel-' . date('Y-m-d') . '.log'), Logger::DEBUG));
        $this->logger->pushHandler(new FirePHPHandler());
    }

    /**
     * Get Jobs for the User
     * @param $user_id
     * @return array
     */
    public function getUsersJobs($user_id)
    {
        $cuser = User::find($user_id);
        if (!$cuser) return $this->getEmptyJobResponse();

        $jobs = $this->getJobsByUserType($cuser);
        $usertype = $this->getUserType($cuser);
        $emergencyJobs = [];
        $normalJobs = [];

        foreach ($jobs as $jobitem) {
            $this->categorizeJob($jobitem, $emergencyJobs, $normalJobs);
        }

        $normalJobs = $this->sortAndCheckJobs($normalJobs, $user_id);

        return [
            'emergencyJobs' => $emergencyJobs,
            'normalJobs' => $normalJobs,
            'cuser' => $cuser,
            'usertype' => $usertype
        ];
    }

     /**
     * Get the jobs based on user type (customer or translator)
     */
    private function getJobsByUserType($user)
    {
        if ($user->is('customer')) {
            return $this->getCustomerJobs($user);
        } elseif ($user->is('translator')) {
            return Job::getTranslatorJobs($user->id, 'new')->pluck('jobs')->all();
        }

        return [];
    }

    /**
     * Return a standard empty response.
     * 
     * @return array
     */
    private function getEmptyResponse()
    {
        return [
            'emergencyJobs' => [],
            'normalJobs' => [],
            'jobs' => [],
            'cuser' => null,
            'usertype' => '',
            'numpages' => 0,
            'pagenum' => 0
        ];
    }

    /**
     * Return an empty job response
     */
    private function getEmptyJobResponse()
    {
        return [
            'emergencyJobs' => [],
            'normalJobs' => [],
            'cuser' => null,
            'usertype' => ''
        ];
    }

    /**
     * Get job history for a customer.
     * 
     * @param User $user
     * @param int $page
     * @return array
     */
    private function getCustomerJobsHistory(User $user, $page)
    {
        $usertype = 'customer';
        $jobs = $user->jobs()
            ->with('user.userMeta', 'user.average', 'translatorJobRel.user.average', 'language', 'feedback', 'distance')
            ->whereIn('status', ['completed', 'withdrawbefore24', 'withdrawafter24', 'timedout'])
            ->orderBy('due', 'desc')
            ->paginate(15, ['*'], 'page', $page);

        return [
            'emergencyJobs' => [],
            'normalJobs' => [],
            'jobs' => $jobs,
            'cuser' => $user,
            'usertype' => $usertype,
            'numpages' => $jobs->lastPage(),
            'pagenum' => $page
        ];
    }


     /**
     * Get the jobs for a customer
     *
     * @param User $user
     * @return mixed
     */
    private function getCustomerJobs(User $user)
    {
        return $user->jobs()
            ->with('user.userMeta', 'user.average', 'translatorJobRel.user.average', 'language', 'feedback')
            ->whereIn('status', ['pending', 'assigned', 'started'])
            ->orderBy('due', 'asc')
            ->get();
    }
      /**
     * Get customer job history
     */
    private function getCustomerJobHistory(User $cuser, $page)
    {
        return $cuser->jobs()
            ->with('user.userMeta', 'user.average', 'translatorJobRel.user.average', 'language', 'feedback', 'distance')
            ->whereIn('status', ['completed', 'withdrawbefore24', 'withdrawafter24', 'timedout'])
            ->orderBy('due', 'desc')
            ->paginate(15);
    }

    /**
     * Categorize jobs into emergency and normal
     */
    private function categorizeJob(Job $job, &$emergencyJobs, &$normalJobs)
    {
        if ($job->immediate == 'yes') {
            $emergencyJobs[] = $job;
        } else {
            $normalJobs[] = $job;
        }
    }

    /**
     * Sort normal jobs and add user-specific checks
     */
    private function sortAndCheckJobs(array $jobs, $user_id)
    {
        return collect($jobs)->each(function ($item) use ($user_id) {
            $item['usercheck'] = Job::checkParticularJob($user_id, $item);
        })->sortBy('due')->all();
    }

    /**
     * Get the user type for a customer or translator
     */
    private function getUserType($user)
    {
        if ($user->is('customer')) {
            return 'customer';
        } elseif ($user->is('translator')) {
            return 'translator';
        }

        return '';
    }

    /**
     * Get the user's job history
     * 
     * @param int $user_id
     * @param Request $request
     * @return array
     */
    public function getUsersJobsHistory($user_id, Request $request)
    {
        // Get the page number from request, default to 1 if not set
        $page = $request->get('page', 1);
        
        // Find the user by ID
        $user = User::find($user_id);
        if (!$user) {
            return $this->getEmptyResponse(); // Return early if user not found
        }

        $usertype = '';
        $emergencyJobs = [];
        $normalJobs = [];

        // Check if the user is a customer
        if ($user->is('customer')) {
            return $this->getCustomerJobsHistory($user, $page);
        }

        // Check if the user is a translator
        if ($user->is('translator')) {
            return $this->getTranslatorJobsHistory($user, $page);
        }

        // Return empty response if the user is neither a customer nor a translator
        return $this->getEmptyResponse();
    }

    /**
     * Get job history for a translator.
     * 
     * @param User $user
     * @param int $page
     * @return array
     */
    private function getTranslatorJobsHistory(User $user, $page)
    {
        $usertype = 'translator';
        $jobs = Job::getTranslatorJobsHistoric($user->id, 'historic', $page);
        $totalJobs = $jobs->total();
        $numPages = ceil($totalJobs / 15);

        return [
            'emergencyJobs' => [],
            'normalJobs' => $jobs,
            'jobs' => $jobs,
            'cuser' => $user,
            'usertype' => $usertype,
            'numpages' => $numPages,
            'pagenum' => $page
        ];
    }


     /**
     * Store the job with validations and proper response
     */
    public function store($user, $data)
    {
        $validationResponse = $this->validateJobData($user, $data);
        if ($validationResponse['status'] !== 'success') {
            return $validationResponse;
        }

        $data = $this->prepareJobData($user, $data);
        $job = $user->jobs()->create($data);

        return $this->formatJobStoreResponse($job, $data);
    }

    /**
     * Validate the job data before storing
     */
    private function validateJobData($user, $data)
    {
        $immediate = $data['immediate'] ?? 'no';

        if ($immediate === 'no' && empty($data['due_date'])) {
            return $this->errorResponse('Du måste fylla in alla fält', 'due_date');
        }

        if (empty($data['from_language_id'])) {
            return $this->errorResponse("Du måste fylla in alla fält", 'from_language_id');
        }

        if (empty($data['duration'])) {
            return $this->errorResponse('Du måste fylla in alla fält', 'duration');
        }

        return ['status' => 'success'];
    }

    /**
     * Prepare the job data for storing
     */
    private function prepareJobData($user, $data)
    {
        $data['due'] = $this->getDueDate($data);
        $data['job_for'] = $this->getJobFor($data);
        $data['job_type'] = $this->getJobType($user);
        $data['b_created_at'] = now()->format('Y-m-d H:i:s');
        $data['will_expire_at'] = TeHelper::willExpireAt($data['due'], $data['b_created_at']);
        $data['by_admin'] = $data['by_admin'] ?? 'no';

        return $data;
    }

    /**
     * Get the job due date
     */
    private function getDueDate($data)
    {
        if ($data['immediate'] === 'yes') {
            return Carbon::now()->addMinutes(5)->format('Y-m-d H:i:s');
        }

        return Carbon::createFromFormat('m/d/Y H:i', "{$data['due_date']} {$data['due_time']}")->format('Y-m-d H:i:s');
    }

    /**
     * Get the job type based on user data
     */
    private function getJobType($user)
    {
        $consumerType = $user->userMeta->consumer_type;

        switch ($consumerType) {
            case 'rwsconsumer':
                return 'rws';
            case 'ngo':
                return 'unpaid';
            case 'paid':
                return 'paid';
            default:
                return 'paid';
        }
    }

    /**
     * Get the job 'for' field based on input
     */
    private function getJobFor($data)
    {
        $jobFor = [];

        if (in_array('male', $data['job_for'])) {
            $jobFor[] = 'Man';
        }
        if (in_array('female', $data['job_for'])) {
            $jobFor[] = 'Kvinna';
        }

        if (in_array('normal', $data['job_for'])) {
            $jobFor[] = 'normal';
        }
        if (in_array('certified', $data['job_for'])) {
            $jobFor[] = 'certified';
        }

        return $jobFor;
    }

    /**
     * Format the job store response
     */
    private function formatJobStoreResponse($job, $data)
    {
        return [
            'status' => 'success',
            'id' => $job->id,
            'job_for' => $data['job_for'],
            'customer_town' => $job->user->userMeta->city,
            'customer_type' => $job->user->userMeta->customer_type
        ];
    }

        /**
     * Create an error response
     */
    private function errorResponse($message, $field)
    {
        return [
            'status' => 'fail',
            'message' => $message,
            'field_name' => $field
        ];
    }

    
    /**
     * Store Job Email and Send Notification Email
     *
     * @param array $data
     * @return array
     */
    public function storeJobEmail(array $data)
    {
        $job = Job::findOrFail($data['user_email_job_id'] ?? null);
        
        // Update job's user email and reference
        $this->updateJobEmailAndReference($job, $data);
        
        // If address and other fields are provided, update them
        if (isset($data['address'])) {
            $this->updateJobAddressAndInstructions($job, $data);
        }

        // Send email notification
        $this->sendJobCreatedEmail($job, $data);

        // Create response data
        $response = [
            'type' => $data['user_type'],
            'job' => $job,
            'status' => 'success'
        ];

        // Fire event for job creation
        $jobData = $this->jobToData($job);
        Event::fire(new JobWasCreated($job, $jobData, '*'));

        return $response;
    }

    /**
     * Update Job's email and reference
     *
     * @param Job $job
     * @param array $data
     */
    private function updateJobEmailAndReference(Job $job, array $data)
    {
        $job->user_email = $data['user_email'] ?? '';
        $job->reference = $data['reference'] ?? '';
        $job->save();
    }

    /**
     * Update Job's address, instructions, and town based on provided data
     *
     * @param Job $job
     * @param array $data
     */
    private function updateJobAddressAndInstructions(Job $job, array $data)
    {
        $user = $job->user()->first();
        $job->address = $data['address'] ?: $user->userMeta->address;
        $job->instructions = $data['instructions'] ?: $user->userMeta->instructions;
        $job->town = $data['town'] ?: $user->userMeta->city;
        $job->save();
    }

    /**
     * Send the Job Created Notification Email
     *
     * @param Job $job
     * @param array $data
     */
    private function sendJobCreatedEmail(Job $job, array $data)
    {
        $user = $job->user;
        $email = !empty($job->user_email) ? $job->user_email : $user->email;
        $name = $user->name;
        $subject = 'Vi har mottagit er tolkbokning. Bokningsnr: #' . $job->id;

        $send_data = [
            'user' => $user,
            'job'  => $job
        ];

        $this->mailer->send($email, $name, $subject, 'emails.job-created', $send_data);
    }

    /**
     * Convert Job data to a format for pushing notifications
     *
     * @param Job $job
     * @return array
     */
    public function jobToData(Job $job)
    {
        $data = [
            'job_id' => $job->id,
            'from_language_id' => $job->from_language_id,
            'immediate' => $job->immediate,
            'duration' => $job->duration,
            'status' => $job->status,
            'gender' => $job->gender,
            'certified' => $job->certified,
            'due' => $job->due,
            'job_type' => $job->job_type,
            'customer_phone_type' => $job->customer_phone_type,
            'customer_physical_type' => $job->customer_physical_type,
            'customer_town' => $job->town,
            'customer_type' => $job->user->userMeta->customer_type,
            'due_date' => $this->getDueDate($job),
            'due_time' => $this->getDueTime($job),
            'job_for' => $this->getJobFor($job)
        ];

        return $data;
    }

   
    /**
     * Get Due Time from Job
     *
     * @param Job $job
     * @return string
     */
    private function getDueTime(Job $job)
    {
        return explode(" ", $job->due)[1];
    }

    /**
     * Get Certified Job for values based on certification type
     *
     * @param string $certified
     * @return array
     */
    private function getCertifiedJobFor(string $certified)
    {
        switch ($certified) {
            case 'both':
                return ['Godkänd tolk', 'Auktoriserad'];
            case 'yes':
                return ['Auktoriserad'];
            case 'n_health':
                return ['Sjukvårdstolk'];
            case 'law':
            case 'n_law':
                return ['Rätttstolk'];
            default:
                return [$certified];
        }
    }

   /**
     * Mark a job as ended and send notifications
     *
     * @param array $post_data
     */
    public function jobEnd(array $post_data = [])
    {
        $jobId = $post_data["job_id"];
        $completedDate = now();
        $jobDetail = Job::with('translatorJobRel')->find($jobId);
        $dueDate = $jobDetail->due;
        $interval = $this->calculateSessionTime($dueDate, $completedDate);

        $jobDetail->end_at = $completedDate;
        $jobDetail->status = 'completed';
        $jobDetail->session_time = $interval;
        $jobDetail->save();

        $user = $jobDetail->user()->first();
        $this->sendSessionEndedEmail($user, $jobDetail, $interval, 'faktura');

        // Send email to the translator
        $translator = $this->getTranslatorForJob($jobDetail);
        $this->sendSessionEndedEmail($translator, $jobDetail, $interval, 'lön');

        // Update translator job relation as completed
        $this->completeTranslatorJob($jobDetail, $post_data['userid']);
    }

    /**
     * Calculate the session time based on the due and completed times.
     *
     * @param string $dueDate
     * @param string $completedDate
     * @return string
     */
    private function calculateSessionTime(string $dueDate, string $completedDate): string
    {
        $start = date_create($dueDate);
        $end = date_create($completedDate);
        $diff = date_diff($end, $start);
        return $diff->h . ':' . $diff->i . ':' . $diff->s;
    }

    /**
     * Send the session ended email notification.
     *
     * @param $user
     * @param Job $job
     * @param string $sessionTime
     * @param string $forText
     */
    private function sendSessionEndedEmail($user, Job $job, string $sessionTime, string $forText)
    {
        $email = !empty($job->user_email) ? $job->user_email : $user->email;
        $name = $user->name;
        $subject = 'Information om avslutad tolkning för bokningsnummer # ' . $job->id;
        $sessionExplode = explode(':', $job->session_time);
        $sessionFormattedTime = $sessionExplode[0] . ' tim ' . $sessionExplode[1] . ' min';

        $data = [
            'user' => $user,
            'job' => $job,
            'session_time' => $sessionFormattedTime,
            'for_text' => $forText
        ];

        $this->mailer->send($email, $name, $subject, 'emails.session-ended', $data);
    }

    /**
     * Get the translator associated with the job.
     *
     * @param Job $job
     * @return \App\Models\User
     */
    private function getTranslatorForJob(Job $job)
    {
        return $job->translatorJobRel->where('completed_at', null)->where('cancel_at', null)->first()->user;
    }

    /**
     * Mark the translator job as completed.
     *
     * @param Job $job
     * @param int $userId
     */
    private function completeTranslatorJob(Job $job, int $userId)
    {
        $translatorJobRel = $job->translatorJobRel->where('completed_at', null)->where('cancel_at', null)->first();
        $translatorJobRel->completed_at = now();
        $translatorJobRel->completed_by = $userId;
        $translatorJobRel->save();
    }

    /**
     * Get all potential jobs based on user ID.
     *
     * @param int $user_id
     * @return array
     */
    public function getPotentialJobIdsWithUserId(int $user_id)
    {
        $userMeta = UserMeta::where('user_id', $user_id)->first();
        $translatorType = $userMeta->translator_type;
        $jobType = $this->determineJobTypeBasedOnTranslator($translatorType);

        $languages = UserLanguages::where('user_id', '=', $user_id)->get();
        $userLanguage = collect($languages)->pluck('lang_id')->all();
        $gender = $userMeta->gender;
        $translatorLevel = $userMeta->translator_level;

        $jobIds = Job::getJobs($user_id, $jobType, 'pending', $userLanguage, $gender, $translatorLevel);

        // Filter out jobs based on town checks
        $jobIds = $this->filterJobsByTown($jobIds, $user_id);

        return TeHelper::convertJobIdsInObjs($jobIds);
    }

    /**
     * Determine job type based on the translator type.
     *
     * @param string $translatorType
     * @return string
     */
    private function determineJobTypeBasedOnTranslator(string $translatorType): string
    {
        switch ($translatorType) {
            case 'professional':
                return 'paid';
            case 'rwstranslator':
                return 'rws';
            case 'volunteer':
            default:
                return 'unpaid';
        }
    }

    /**
     * Filter out jobs based on the user's town and job criteria.
     *
     * @param array $jobIds
     * @param int $userId
     * @return array
     */
    private function filterJobsByTown(array $jobIds, int $userId): array
    {
        foreach ($jobIds as $key => $job) {
            $job = Job::find($job->id);
            $jobUserId = $job->user_id;
            $checkTown = Job::checkTowns($jobUserId, $userId);

            // Remove jobs where the phone type is invalid or the town check fails
            if (($job->customer_phone_type == 'no' || $job->customer_phone_type == '') 
                && $job->customer_physical_type == 'yes' 
                && !$checkTown) {
                unset($jobIds[$key]);
            }
        }

        return $jobIds;
    }
    
    /**
     * Send push notifications to suitable translators for a job.
     *
     * @param Job $job
     * @param array $data
     * @param int $excludeUserId
     */
    public function sendNotificationTranslator(Job $job, array $data, int $excludeUserId)
    {
        $users = User::all();
        $translatorArray = [];
        $delayTranslatorArray = [];

        foreach ($users as $user) {
            if ($this->isEligibleTranslator($user, $excludeUserId, $data)) {
                $this->processJobForTranslator($job, $user, $data, $translatorArray, $delayTranslatorArray);
            }
        }

        $this->sendPushNotifications($job, $data, $translatorArray, $delayTranslatorArray);
    }

    /**
     * Check if the user is eligible for receiving push notifications for the job.
     *
     * @param User $user
     * @param int $excludeUserId
     * @param array $data
     * @return bool
     */
    private function isEligibleTranslator(User $user, int $excludeUserId, array $data): bool
    {
        return $user->user_type == '2' && $user->status == '1' && $user->id != $excludeUserId
            && !$this->isNeedToSendPush($user->id)
            && !($data['immediate'] == 'yes' && TeHelper::getUsermeta($user->id, 'not_get_emergency') == 'yes');
    }

    /**
     * Process a job for a particular translator and categorize them based on push notification delay.
     *
     * @param Job $job
     * @param User $user
     * @param array $data
     * @param array $translatorArray
     * @param array $delayTranslatorArray
     */
    private function processJobForTranslator(Job $job, User $user, array $data, array &$translatorArray, array &$delayTranslatorArray)
    {
        $jobs = $this->getPotentialJobIdsWithUserId($user->id);
        foreach ($jobs as $potentialJob) {
            if ($job->id == $potentialJob->id) {
                $this->checkAndCategorizeTranslatorForJob($user, $job, $data, $translatorArray, $delayTranslatorArray);
            }
        }
    }

    /**
     * Check job assignment and categorize translator into appropriate array.
     *
     * @param User $user
     * @param Job $job
     * @param array $data
     * @param array $translatorArray
     * @param array $delayTranslatorArray
     */
    private function checkAndCategorizeTranslatorForJob(User $user, Job $job, array $data, array &$translatorArray, array &$delayTranslatorArray)
    {
        $userId = $user->id;
        $jobForTranslator = Job::assignedToPaticularTranslator($userId, $job->id);

        if ($jobForTranslator == 'SpecificJob' && Job::checkParticularJob($userId, $job) != 'userCanNotAcceptJob') {
            if ($this->isNeedToDelayPush($userId)) {
                $delayTranslatorArray[] = $user;
            } else {
                $translatorArray[] = $user;
            }
        }
    }

    /**
     * Send push notifications to the categorized translators.
     *
     * @param Job $job
     * @param array $data
     * @param array $translatorArray
     * @param array $delayTranslatorArray
     */
    private function sendPushNotifications(Job $job, array $data, array $translatorArray, array $delayTranslatorArray)
    {
        $data['language'] = TeHelper::fetchLanguageFromJobId($data['from_language_id']);
        $data['notification_type'] = 'suitable_job';
        $msgContents = $this->prepareNotificationMessage($data);

        $msgText = ['en' => $msgContents];

        $this->logPushNotification($job, $translatorArray, $delayTranslatorArray, $msgText, $data);

        $this->sendPushNotificationToSpecificUsers($translatorArray, $job->id, $data, $msgText, false);
        $this->sendPushNotificationToSpecificUsers($delayTranslatorArray, $job->id, $data, $msgText, true);
    }

    /**
     * Prepare the content of the push notification message.
     *
     * @param array $data
     * @return string
     */
    private function prepareNotificationMessage(array $data): string
    {
        if ($data['immediate'] == 'no') {
            return 'Ny bokning för ' . $data['language'] . 'tolk ' . $data['duration'] . 'min ' . $data['due'];
        }
        return 'Ny akutbokning för ' . $data['language'] . 'tolk ' . $data['duration'] . 'min';
    }

    /**
     * Log the details of the push notification for debugging purposes.
     *
     * @param Job $job
     * @param array $translatorArray
     * @param array $delayTranslatorArray
     * @param array $msgText
     * @param array $data
     */
    private function logPushNotification(Job $job, array $translatorArray, array $delayTranslatorArray, array $msgText, array $data)
    {
        $logger = new Logger('push_logger');
        $logger->pushHandler(new StreamHandler(storage_path('logs/push/laravel-' . date('Y-m-d') . '.log'), Logger::DEBUG));
        $logger->pushHandler(new FirePHPHandler());
        $logger->addInfo('Push send for job ' . $job->id, [$translatorArray, $delayTranslatorArray, $msgText, $data]);
    }

    /**
     * Send SMS notifications to translators.
     *
     * @param Job $job
     * @return int
     */
    public function sendSMSNotificationToTranslator(Job $job): int
    {
        $translators = $this->getPotentialTranslators($job);
        $jobPosterMeta = UserMeta::where('user_id', $job->user_id)->first();

        $message = $this->prepareSMSMessage($job, $jobPosterMeta);

        foreach ($translators as $translator) {
            $this->notificationService->sendSMS($translator, $message);
        }

        return count($translators);
    }

    /**
     * Prepare the SMS message based on job details.
     *
     * @param Job $job
     * @param UserMeta $jobPosterMeta
     * @return string
     */
    private function prepareSMSMessage(Job $job, UserMeta $jobPosterMeta): string
    {
        $date = date('d.m.Y', strtotime($job->due));
        $time = date('H:i', strtotime($job->due));
        $duration = $this->convertToHoursMins($job->duration);
        $jobId = $job->id;
        $city = $job->city ?: $jobPosterMeta->city;

        $phoneJobMessageTemplate = trans('sms.phone_job', ['date' => $date, 'time' => $time, 'duration' => $duration, 'jobId' => $jobId]);
        $physicalJobMessageTemplate = trans('sms.physical_job', ['date' => $date, 'time' => $time, 'town' => $city, 'duration' => $duration, 'jobId' => $jobId]);

        if ($job->customer_physical_type == 'yes' && $job->customer_phone_type == 'no') {
            return $physicalJobMessageTemplate;
        }

        return $phoneJobMessageTemplate;
    }

    
   /**
     * Determine if the push notification needs to be delayed based on user preferences.
     *
     * @param int $userId
     * @return bool
     */
    public function isNeedToDelayPush(int $userId): bool
    {
        return DateTimeHelper::isNightTime() && $this->isUserOptedOutOfNighttimeNotifications($userId);
    }

    /**
     * Check if the user has opted out of nighttime notifications.
     *
     * @param int $userId
     * @return bool
     */
    private function isUserOptedOutOfNighttimeNotifications(int $userId): bool
    {
        $notGetNightTime = TeHelper::getUsermeta($userId, 'not_get_nighttime');
        return $notGetNightTime === 'yes';
    }

    /**
     * Check if push notifications should be sent to a user.
     *
     * @param int $userId
     * @return bool
     */
    public function isNeedToSendPush(int $userId): bool
    {
        $notGetNotification = TeHelper::getUsermeta($userId, 'not_get_notification');
        return $notGetNotification !== 'yes';
    }

    /**
     * Send push notifications to specific users via OneSignal.
     *
     * @param array $users
     * @param int $jobId
     * @param array $data
     * @param array $msgText
     * @param bool $isNeedDelay
     */
    public function sendPushNotificationToSpecificUsers(array $users, int $jobId, array $data, array $msgText, bool $isNeedDelay)
    {
        $logger = $this->getPushLogger();
        $this->logPushDetails($logger, $jobId, $users, $data, $msgText, $isNeedDelay);

        $onesignalCredentials = $this->getOneSignalCredentials();
        $userTags = $this->getUserTagsStringFromArray($users);

        $fields = $this->preparePushFields($jobId, $data, $msgText, $userTags, $onesignalCredentials, $isNeedDelay);
        
        // Send the push notification via OneSignal API
        $this->sendPushViaCurl($fields, $onesignalCredentials['auth_key'], $logger);
    }

    /**
     * Get OneSignal credentials based on the environment.
     *
     * @return array
     */
    private function getOneSignalCredentials(): array
    {
        if (env('APP_ENV') === 'prod') {
            return [
                'app_id' => config('app.prodOnesignalAppID'),
                'auth_key' => sprintf("Authorization: Basic %s", config('app.prodOnesignalApiKey')),
            ];
        }

        return [
            'app_id' => config('app.devOnesignalAppID'),
            'auth_key' => sprintf("Authorization: Basic %s", config('app.devOnesignalApiKey')),
        ];
    }

    /**
     * Prepare the fields for the push notification request.
     *
     * @param int $jobId
     * @param array $data
     * @param array $msgText
     * @param string $userTags
     * @param array $onesignalCredentials
     * @param bool $isNeedDelay
     * @return array
     */
    private function preparePushFields(int $jobId, array $data, array $msgText, string $userTags, array $onesignalCredentials, bool $isNeedDelay): array
    {
        $iosSound = 'default';
        $androidSound = 'default';

        if ($data['notification_type'] === 'suitable_job') {
            $soundType = ($data['immediate'] === 'no') ? 'normal_booking' : 'emergency_booking';
            $androidSound = $soundType;
            $iosSound = $soundType . '.mp3';
        }

        $fields = [
            'app_id' => $onesignalCredentials['app_id'],
            'tags' => json_decode($userTags),
            'data' => $data,
            'title' => ['en' => 'DigitalTolk'],
            'contents' => $msgText,
            'ios_badgeType' => 'Increase',
            'ios_badgeCount' => 1,
            'android_sound' => $androidSound,
            'ios_sound' => $iosSound,
        ];

        if ($isNeedDelay) {
            $fields['send_after'] = DateTimeHelper::getNextBusinessTimeString();
        }

        return $fields;
    }

    /**
     * Log the details of the push notification for debugging purposes.
     *
     * @param Logger $logger
     * @param int $jobId
     * @param array $users
     * @param array $data
     * @param array $msgText
     * @param bool $isNeedDelay
     */
    private function logPushDetails(Logger $logger, int $jobId, array $users, array $data, array $msgText, bool $isNeedDelay)
    {
        $logger->addInfo('Push send for job ' . $jobId, [
            'users' => $users,
            'data' => $data,
            'msg_text' => $msgText,
            'is_need_delay' => $isNeedDelay,
        ]);
    }

    /**
     * Initialize and return the logger for push notifications.
     *
     * @return Logger
     */
    private function getPushLogger(): Logger
    {
        $logger = new Logger('push_logger');
        $logger->pushHandler(new StreamHandler(storage_path('logs/push/laravel-' . date('Y-m-d') . '.log'), Logger::DEBUG));
        $logger->pushHandler(new FirePHPHandler());

        return $logger;
    }

    /**
     * Send the push notification via the OneSignal API using cURL.
     *
     * @param array $fields
     * @param string $authKey
     * @param Logger $logger
     */
    private function sendPushViaCurl(array $fields, string $authKey, Logger $logger)
    {
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, "https://onesignal.com/api/v1/notifications");
        curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json', $authKey]);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HEADER, false);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($fields));
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);

        $response = curl_exec($ch);
        $logger->addInfo('Push send for job response', [$response]);
        curl_close($ch);
    }

    /**
     * Get potential translators based on job details.
     *
     * @param Job $job
     * @return mixed
     */
    public function getPotentialTranslators(Job $job)
    {
        $translatorType = $this->getTranslatorTypeByJobType($job->job_type);
        $translatorLevel = $this->getTranslatorLevelsByCertification($job->certified);
        $blacklist = $this->getBlacklist($job->user_id);
        $translatorsId = $blacklist->pluck('translator_id')->all();

        return User::getPotentialUsers($translatorType, $job->from_language_id, $job->gender, $translatorLevel, $translatorsId);
    }

    /**
     * Determine the translator type based on job type.
     *
     * @param string $jobType
     * @return string
     */
    private function getTranslatorTypeByJobType(string $jobType): string
    {
        $translatorTypes = [
            'paid' => 'professional',
            'rws'  => 'rwstranslator',
            'unpaid' => 'volunteer'
        ];

        return $translatorTypes[$jobType] ?? 'professional'; // Default to 'professional' if not found
    }

    /**
     * Get translator levels based on the certification details of the job.
     *
     * @param string|null $certified
     * @return array
     */
    private function getTranslatorLevelsByCertification($certified): array
    {
        $levels = [];

        if ($certified) {
            if (in_array($certified, ['yes', 'both'])) {
                $levels = ['Certified', 'Certified with specialisation in law', 'Certified with specialisation in health care'];
            } elseif ($certified === 'law' || $certified === 'n_law') {
                $levels = ['Certified with specialisation in law'];
            } elseif ($certified === 'health' || $certified === 'n_health') {
                $levels = ['Certified with specialisation in health care'];
            } else {
                $levels = ['Layman', 'Read Translation courses'];
            }
        } else {
            $levels = ['Certified', 'Certified with specialisation in law', 'Certified with specialisation in health care', 'Layman', 'Read Translation courses'];
        }

        return $levels;
    }

    /**
     * Get the blacklist for a specific user.
     *
     * @param int $userId
     * @return \Illuminate\Database\Eloquent\Collection
     */
    private function getBlacklist(int $userId)
    {
        return UsersBlacklist::where('user_id', $userId)->get();
    }

    /**
     * Update the job details and handle the necessary notifications.
     *
     * @param int $id
     * @param array $data
     * @param $cuser
     * @return array
     */
    public function updateJob(int $id, array $data, $cuser)
    {
        $job = Job::find($id);
        $logData = [];

        $currentTranslator = $this->getCurrentTranslator($job);
        $changeTranslator = $this->changeTranslator($currentTranslator, $data, $job);
        $changeDue = $this->changeDue($job->due, $data['due']);
        $langChanged = $this->changeLanguage($job, $data);

        if ($changeTranslator['translatorChanged']) {
            $logData[] = $changeTranslator['log_data'];
        }

        if ($changeDue['dateChanged']) {
            $logData[] = $changeDue['log_data'];
        }

        if ($langChanged) {
            $logData[] = [
                'old_lang' => TeHelper::fetchLanguageFromJobId($job->from_language_id),
                'new_lang' => TeHelper::fetchLanguageFromJobId($data['from_language_id'])
            ];
        }

        // Log the update action
        $this->logJobUpdate($cuser, $id, $logData);

        // Update job details
        $job->update([
            'due' => $data['due'],
            'from_language_id' => $data['from_language_id'],
            'admin_comments' => $data['admin_comments'],
            'reference' => $data['reference']
        ]);

        // Check if the due date is in the past or in the future
        if ($job->due <= Carbon::now()) {
            return ['Updated'];
        } else {
            // Send notifications for changes if required
            $this->sendNotifications($job, $changeDue, $changeTranslator, $langChanged, $data);
        }
    }

    /**
     * Get the current translator assigned to the job.
     *
     * @param Job $job
     * @return mixed
     */
    private function getCurrentTranslator(Job $job)
    {
        $translator = $job->translatorJobRel->whereNull('cancel_at')->first();

        if (is_null($translator)) {
            $translator = $job->translatorJobRel->whereNotNull('completed_at')->first();
        }

        return $translator;
    }

    /**
     * Log the job update details.
     *
     * @param $cuser
     * @param int $jobId
     * @param array $logData
     */
    private function logJobUpdate($cuser, int $jobId, array $logData)
    {
        $this->logger->addInfo('USER #' . $cuser->id . '(' . $cuser->name . ') has updated booking <a class="openjob" href="/admin/jobs/' . $jobId . '">#' . $jobId . '</a> with data: ', $logData);
    }

    /**
     * Send notifications when a job is updated (due date, translator, language).
     *
     * @param Job $job
     * @param array $changeDue
     * @param array $changeTranslator
     * @param bool $langChanged
     * @param array $data
     */
    private function sendNotifications(Job $job, array $changeDue, array $changeTranslator, bool $langChanged, array $data)
    {
        if ($changeDue['dateChanged']) {
            $this->notificationService->sendChangedDateNotification($job, $changeDue['old_time']);
        }

        if ($changeTranslator['translatorChanged']) {
            $this->notificationService->sendChangedTranslatorNotification($job, $changeTranslator['old_translator'], $changeTranslator['new_translator']);
        }

        if ($langChanged) {
            $this->sendChangedLangNotification($job, $data['from_language_id']);
        }
    }


   /**
     * Handle status change for a job.
     *
     * @param $job
     * @param $data
     * @param $changedTranslator
     * @return array
     */
    private function changeStatus($job, $data, $changedTranslator)
    {
        $oldStatus = $job->status;
        $statusChanged = false;

        // If status is different, attempt to change it
        if ($oldStatus !== $data['status']) {
            $statusChanged = $this->processStatusChange($job, $data, $changedTranslator);
            
            if ($statusChanged) {
                return [
                    'statusChanged' => true,
                    'log_data' => [
                        'old_status' => $oldStatus,
                        'new_status' => $data['status']
                    ]
                ];
            }
        }

        return ['statusChanged' => false];
    }

    /**
     * Process the status change by delegating to the relevant handler method.
     *
     * @param $job
     * @param $data
     * @param $changedTranslator
     * @return bool
     */
    private function processStatusChange($job, $data, $changedTranslator)
    {
        $statusHandler = [
            'timedout' => 'changeTimedoutStatus',
            'completed' => 'changeCompletedStatus',
            'started' => 'changeStartedStatus',
            'pending' => 'changePendingStatus',
            'withdrawafter24' => 'changeWithdrawafter24Status',
            'assigned' => 'changeAssignedStatus'
        ];

        $status = $data['status'];
        if (isset($statusHandler[$status])) {
            return $this->{$statusHandler[$status]}($job, $data, $changedTranslator);
        }

        return false;
    }

    /**
     * Handle the status change when the job is timed out.
     *
     * @param $job
     * @param $data
     * @param $changedTranslator
     * @return bool
     */
    private function changeTimedoutStatus($job, $data, $changedTranslator)
    {
        // Update the status to the new value
        $job->status = $data['status'];
        
        $user = $job->user()->first();
        $email = !empty($job->user_email) ? $job->user_email : $user->email;
        $name = $user->name;
        $dataEmail = ['user' => $user, 'job' => $job];

        if ($data['status'] === 'pending') {
            return $this->handlePendingStatus($job, $email, $name, $dataEmail);
        } elseif ($changedTranslator) {
            return $this->handleChangedTranslator($job, $email, $name, $dataEmail);
        }

        return false;
    }

    /**
     * Handle the "pending" status change, sending an email and resetting the job.
     *
     * @param $job
     * @param $email
     * @param $name
     * @param $dataEmail
     * @return bool
     */
    private function handlePendingStatus($job, $email, $name, $dataEmail)
    {
        // Reset job data
        $job->created_at = now();
        $job->emailsent = 0;
        $job->emailsenttovirpal = 0;
        $job->save();

        $subject = 'Vi har nu återöppnat er bokning av ' . TeHelper::fetchLanguageFromJobId($job->from_language_id) . 'tolk för bokning #' . $job->id;
        $this->mailer->send($email, $name, $subject, 'emails.job-change-status-to-customer', $dataEmail);

        // Send push notification to all suitable translators
        $jobData = $this->jobToData($job);
        $this->sendNotificationTranslator($job, $jobData, '*');

        return true;
    }

    /**
     * Handle the case where the translator has changed and notify the customer.
     *
     * @param $job
     * @param $email
     * @param $name
     * @param $dataEmail
     * @return bool
     */
    private function handleChangedTranslator($job, $email, $name, $dataEmail)
    {
        $job->save();
        $subject = 'Bekräftelse - tolk har accepterat er bokning (bokning # ' . $job->id . ')';
        $this->mailer->send($email, $name, $subject, 'emails.job-accepted', $dataEmail);

        return true;
    }

     /**
     * Change the status of a completed job.
     *
     * @param $job
     * @param $data
     * @return bool
     */
    private function changeCompletedStatus($job, $data)
    {
        // Update the job status
        $job->status = $data['status'];

        if ($data['status'] === 'timedout') {
            if (empty($data['admin_comments'])) {
                return false;
            }
            $job->admin_comments = $data['admin_comments'];
        }

        $job->save();

        return true;
    }

    /**
     * Change the status of a started job.
     *
     * @param $job
     * @param $data
     * @return bool
     */
    private function changeStartedStatus($job, $data)
    {
        // Update the job status and admin comments
        $job->status = $data['status'];

        if (empty($data['admin_comments'])) {
            return false;
        }

        $job->admin_comments = $data['admin_comments'];

        // Handle completed status
        if ($data['status'] === 'completed') {
            return $this->handleCompletedJob($job, $data);
        }

        $job->save();
        return true;
    }

    /**
     * Handle the logic for when a job is marked as completed.
     *
     * @param $job
     * @param $data
     * @return bool
     */
    private function handleCompletedJob($job, $data)
    {
        $user = $job->user()->first();

        if (empty($data['sesion_time'])) {
            return false;
        }

        // Session time
        $interval = $data['sesion_time'];
        $diff = explode(':', $interval);
        $job->end_at = now();
        $job->session_time = $interval;
        $sessionTime = $diff[0] . ' tim ' . $diff[1] . ' min';

        $this->notificationService->sendSessionEndEmails($user, $job, $sessionTime);
        $this->notificationService->sendTranslatorSessionEndEmails($job, $sessionTime);

        $job->save();
        return true;
    }


    /**
     * Change the status of a pending job.
     *
     * @param $job
     * @param $data
     * @param $changedTranslator
     * @return bool
     */
    private function changePendingStatus($job, $data, $changedTranslator)
    {
        // Update job status and admin comments
        $job->status = $data['status'];
        if (empty($data['admin_comments']) && $data['status'] === 'timedout') {
            return false;
        }

        $job->admin_comments = $data['admin_comments'];

        $user = $job->user()->first();
        $email = $job->user_email ?: $user->email;
        $name = $user->name;
        $dataEmail = [
            'user' => $user,
            'job'  => $job
        ];

        if ($data['status'] === 'assigned' && $changedTranslator) {
            return $this->handleAssignedJob($job, $data, $dataEmail, $user);
        } else {
            return $this->handleCancelledJob($job, $dataEmail, $user);
        }

        $job->save();
        return true;
    }

    /**
     * Handle the job when it is assigned to a translator.
     *
     * @param $job
     * @param $data
     * @param $dataEmail
     * @param $user
     * @return bool
     */
    private function handleAssignedJob($job, $data, $dataEmail, $user)
    {
        // Save the job and send notification
        $job->save();
        $jobData = $this->jobToData($job);

        $subject = 'Bekräftelse - tolk har accepterat er bokning (bokning # ' . $job->id . ')';
        $this->mailer->send($user->email, $user->name, $subject, 'emails.job-accepted', $dataEmail);

        $translator = Job::getJobsAssignedTranslatorDetail($job);
        $this->mailer->send($translator->email, $translator->name, $subject, 'emails.job-changed-translator-new-translator', $dataEmail);

        // Send reminders
        $language = TeHelper::fetchLanguageFromJobId($job->from_language_id);
        $this->notificationService->sendSessionStartRemindNotification($user, $job, $language, $job->due, $job->duration);
        $this->notificationService->sendSessionStartRemindNotification($translator, $job, $language, $job->due, $job->duration);

        return true;
    }

    /**
     * Handle the job when it is cancelled or withdrawn.
     *
     * @param $job
     * @param $dataEmail
     * @param $user
     * @return bool
     */
    private function handleCancelledJob($job, $dataEmail, $user)
    {
        $subject = 'Avbokning av bokningsnr: #' . $job->id;
        $this->mailer->send($user->email, $user->name, $subject, 'emails.status-changed-from-pending-or-assigned-customer', $dataEmail);

        return true;
    }

   

    /**
     * @param $job
     * @param $data
     * @return bool
     */
    private function changeWithdrawafter24Status($job, $data)
    {
        if (in_array($data['status'], ['timedout'])) {
            $job->status = $data['status'];
            if ($data['admin_comments'] == '') return false;
            $job->admin_comments = $data['admin_comments'];
            $job->save();
            return true;
        }
        return false;
    }

    /**
     * @param $job
     * @param $data
     * @return bool
     */
    private function changeAssignedStatus($job, $data)
    {
        if (in_array($data['status'], ['withdrawbefore24', 'withdrawafter24', 'timedout'])) {
            $job->status = $data['status'];
            if ($data['admin_comments'] == '' && $data['status'] == 'timedout') return false;
            $job->admin_comments = $data['admin_comments'];
            if (in_array($data['status'], ['withdrawbefore24', 'withdrawafter24'])) {
                $user = $job->user()->first();

                if (!empty($job->user_email)) {
                    $email = $job->user_email;
                } else {
                    $email = $user->email;
                }
                $name = $user->name;
                $dataEmail = [
                    'user' => $user,
                    'job'  => $job
                ];

                $subject = 'Information om avslutad tolkning för bokningsnummer #' . $job->id;
                $this->mailer->send($email, $name, $subject, 'emails.status-changed-from-pending-or-assigned-customer', $dataEmail);

                $user = $job->translatorJobRel->where('completed_at', Null)->where('cancel_at', Null)->first();

                $email = $user->user->email;
                $name = $user->user->name;
                $subject = 'Information om avslutad tolkning för bokningsnummer # ' . $job->id;
                $dataEmail = [
                    'user' => $user,
                    'job'  => $job
                ];
                $this->mailer->send($email, $name, $subject, 'emails.job-cancel-translator', $dataEmail);
            }
            $job->save();
            return true;
        }
        return false;
    }

    /**
     * @param $current_translator
     * @param $data
     * @param $job
     * @return array
     */
    private function changeTranslator($current_translator, $data, $job)
    {
        $translatorChanged = false;

        if (!is_null($current_translator) || (isset($data['translator']) && $data['translator'] != 0) || $data['translator_email'] != '') {
            $log_data = [];
            if (!is_null($current_translator) && ((isset($data['translator']) && $current_translator->user_id != $data['translator']) || $data['translator_email'] != '') && (isset($data['translator']) && $data['translator'] != 0)) {
                if ($data['translator_email'] != '') $data['translator'] = User::where('email', $data['translator_email'])->first()->id;
                $new_translator = $current_translator->toArray();
                $new_translator['user_id'] = $data['translator'];
                unset($new_translator['id']);
                $new_translator = Translator::create($new_translator);
                $current_translator->cancel_at = Carbon::now();
                $current_translator->save();
                $log_data[] = [
                    'old_translator' => $current_translator->user->email,
                    'new_translator' => $new_translator->user->email
                ];
                $translatorChanged = true;
            } elseif (is_null($current_translator) && isset($data['translator']) && ($data['translator'] != 0 || $data['translator_email'] != '')) {
                if ($data['translator_email'] != '') $data['translator'] = User::where('email', $data['translator_email'])->first()->id;
                $new_translator = Translator::create(['user_id' => $data['translator'], 'job_id' => $job->id]);
                $log_data[] = [
                    'old_translator' => null,
                    'new_translator' => $new_translator->user->email
                ];
                $translatorChanged = true;
            }
            if ($translatorChanged)
                return ['translatorChanged' => $translatorChanged, 'new_translator' => $new_translator, 'log_data' => $log_data];

        }

        return ['translatorChanged' => $translatorChanged];
    }

    /**
     * @param $old_due
     * @param $new_due
     * @return array
     */
    private function changeDue($old_due, $new_due)
    {
        $dateChanged = false;
        if ($old_due != $new_due) {
            $log_data = [
                'old_due' => $old_due,
                'new_due' => $new_due
            ];
            $dateChanged = true;
            return ['dateChanged' => $dateChanged, 'log_data' => $log_data];
        }

        return ['dateChanged' => $dateChanged];

    }

    /**
     * Check if the language of the job has changed and handle logging.
     *
     * @param Job $job
     * @param array $data
     * @return bool
     */
    private function changeLanguage(Job $job, array $data): bool
    {
        $langChanged = false;

        if ($job->from_language_id != $data['from_language_id']) {
            $job->from_language_id = $data['from_language_id'];
            $langChanged = true;
        }

        return $langChanged;
    }


  /**
     * Send notifications when the translator for a job is changed.
     *
     * @param Job $job
     * @param Translator|null $current_translator
     * @param Translator|null $new_translator
     */
    public function sendChangedTranslatorNotification($job, $current_translator, $new_translator)
    {
        $subject = 'Meddelande om tilldelning av tolkuppdrag för uppdrag #' . $job->id;
        $data = ['job' => $job];

        // Send email to the customer (user who requested the job)
        $this->sendEmailToUser($job->user, $subject, 'emails.job-changed-translator-customer', $data);

        // Send email to the current translator, if exists
        if ($current_translator) {
            $this->sendEmailToUser($current_translator->user, $subject, 'emails.job-changed-translator-old-translator', $data);
        }

        // Send email to the new translator, if exists
        if ($new_translator) {
            $this->sendEmailToUser($new_translator->user, $subject, 'emails.job-changed-translator-new-translator', $data);
        }
    }

    /**
     * Send an email to a user.
     *
     * @param User $user
     * @param string $subject
     * @param string $template
     * @param array $data
     */
    private function sendEmailToUser($user, $subject, $template, $data)
    {
        if (!$user) {
            return;
        }

        $name = $user->name;
        $email = $user->email;

        // Fallback to job's user_email if available
        if (empty($email) && !empty($data['job']->user_email)) {
            $email = $data['job']->user_email;
        }

        // Send email using the mailer
        $this->mailer->send($email, $name, $subject, $template, array_merge($data, ['user' => $user]));
    }


   

    /**
     * @param $job
     * @param $old_lang
     */
    public function sendChangedLangNotification($job, $old_lang)
    {
        $user = $job->user()->first();
        if (!empty($job->user_email)) {
            $email = $job->user_email;
        } else {
            $email = $user->email;
        }
        $name = $user->name;
        $subject = 'Meddelande om ändring av tolkbokning för uppdrag # ' . $job->id . '';
        $data = [
            'user'     => $user,
            'job'      => $job,
            'old_lang' => $old_lang
        ];
        $this->mailer->send($email, $name, $subject, 'emails.job-changed-lang', $data);
        $translator = Job::getJobsAssignedTranslatorDetail($job);
        $this->mailer->send($translator->email, $translator->name, $subject, 'emails.job-changed-date', $data);
    }

    /**
     * Function to send Job Expired Push Notification
     * @param $job
     * @param $user
     */
    public function sendExpiredNotification($job, $user)
    {
        $data = array();
        $data['notification_type'] = 'job_expired';
        $language = TeHelper::fetchLanguageFromJobId($job->from_language_id);
        $msg_text = array(
            "en" => 'Tyvärr har ingen tolk accepterat er bokning: (' . $language . ', ' . $job->duration . 'min, ' . $job->due . '). Vänligen pröva boka om tiden.'
        );

        if ($this->isNeedToSendPush($user->id)) {
            $users_array = array($user);
            $this->sendPushNotificationToSpecificUsers($users_array, $job->id, $data, $msg_text, $this->isNeedToDelayPush($user->id));
        }
    }

    /**
     * Send a notification when a job is canceled by the admin.
     *
     * @param int $job_id
     */
    public function sendNotificationByAdminCancelJob($job_id)
    {
        $job = Job::findOrFail($job_id);
        $user_meta = $job->user->userMeta()->first();
        
        // Build data array for the notification
        $data = $this->buildJobData($job, $user_meta);

        // Send the notification to all suitable translators
        $this->sendNotificationTranslator($job, $data, '*');
    }

    /**
     * Build the data array needed for sending a job-related notification.
     *
     * @param Job $job
     * @param UserMeta $user_meta
     * @return array
     */
    private function buildJobData($job, $user_meta)
    {
        $data = [
            'job_id' => $job->id,
            'from_language_id' => $job->from_language_id,
            'immediate' => $job->immediate,
            'duration' => $job->duration,
            'status' => $job->status,
            'gender' => $job->gender,
            'certified' => $job->certified,
            'due' => $job->due,
            'job_type' => $job->job_type,
            'customer_phone_type' => $job->customer_phone_type,
            'customer_physical_type' => $job->customer_physical_type,
            'customer_town' => $user_meta->city,
            'customer_type' => $user_meta->customer_type
        ];

        // Extract date and time from the due field
        list($due_date, $due_time) = explode(" ", $job->due);
        $data['due_date'] = $due_date;
        $data['due_time'] = $due_time;

        // Handle job_for based on gender and certification
        $data['job_for'] = $this->getJobForArray($job);

        return $data;
    }

    /**
     * Determine the 'job_for' array based on the gender and certification status of the job.
     *
     * @param Job $job
     * @return array
     */
    private function getJobForArray($job)
    {
        $job_for = [];

        // Gender
        if ($job->gender) {
            $job_for[] = $job->gender == 'male' ? 'Man' : 'Kvinna';
        }

        // Certification
        if ($job->certified) {
            if ($job->certified == 'both') {
                $job_for = array_merge($job_for, ['normal', 'certified']);
            } elseif ($job->certified == 'yes') {
                $job_for[] = 'certified';
            } else {
                $job_for[] = $job->certified;
            }
        }

        return $job_for;
    }

    /**
     * Send session start reminder notification for a pending job.
     *
     * @param User $user
     * @param Job $job
     * @param string $language
     * @param string $due
     * @param string $duration
     */
    private function sendNotificationChangePending($user, $job, $language, $due, $duration)
    {
        $data = ['notification_type' => 'session_start_remind'];
        
        // Build message text based on customer physical type
        $msg_text = $this->buildSessionStartMessage($job, $language, $duration, $due);

        // Send notification if needed
        if ($this->isNeedToSendPush($user->id)) {
            $this->sendPushNotificationToUser($user, $job, $data, $msg_text);
        }
    }

    /**
     * Build the session start reminder message text based on customer physical type.
     *
     * @param Job $job
     * @param string $language
     * @param string $duration
     * @param string $due
     * @return array
     */
    private function buildSessionStartMessage($job, $language, $duration, $due)
    {
        $message = "Vänligen säkerställ att du är förberedd för den tiden. Tack!";
        
        if ($job->customer_physical_type == 'yes') {
            return [
                "en" => "Du har nu fått platstolkningen för {$language} kl {$duration} den {$due}. {$message}"
            ];
        }

        return [
            "en" => "Du har nu fått telefontolkningen för {$language} kl {$duration} den {$due}. {$message}"
        ];
    }

    /**
     * Send a push notification to a specific user.
     *
     * @param User $user
     * @param Job $job
     * @param array $data
     * @param array $msg_text
     */
    private function sendPushNotificationToUser($user, $job, $data, $msg_text)
    {
        $users_array = [$user];
        $this->sendPushNotificationToSpecificUsers(
            $users_array,
            $job->id,
            $data,
            $msg_text,
            $this->isNeedToDelayPush($user->id)
        );
    }

    /**
     * Generate user tags string from an array of users for creating OneSignal notifications.
     *
     * @param array $users
     * @return string
     */
    private function getUserTagsStringFromArray($users)
    {
        $user_tags = '[';
        $first = true;

        foreach ($users as $user) {
            if (!$first) {
                $user_tags .= ',{"operator": "OR"},';
            }
            $first = false;
            
            $user_tags .= '{"key": "email", "relation": "=", "value": "' . strtolower($user->email) . '"}';
        }

        $user_tags .= ']';
        return $user_tags;
    }


    public function acceptJob($data, $user)
    {
        $jobId = $data['job_id'];
        $job = Job::findOrFail($jobId);

        // Check if the translator is already booked for the job
        if ($this->isTranslatorAlreadyBooked($job, $user)) {
            return $this->createFailResponse('Du har redan en bokning den tiden! Bokningen är inte accepterad.');
        }

        // Accept the job
        if ($this->assignJobToTranslator($job, $user)) {
            $this->sendAcceptanceEmail($job, $user);
            $this->sendPushNotification($job, $user);
            return $this->createSuccessResponse($job);
        }

        return $this->createFailResponse('Bokningen kunde inte accepteras.');
    }

    public function acceptJobWithId($jobId, $cuser)
    {
        $job = Job::findOrFail($jobId);

        // Check if the translator is already booked for the job
        if ($this->isTranslatorAlreadyBooked($job, $cuser)) {
            return $this->createFailResponse('Du har redan en bokning den tiden ' . $job->due . '. Du har inte fått denna tolkning');
        }

        // Accept the job
        if ($this->assignJobToTranslator($job, $cuser)) {
            $this->sendAcceptanceEmail($job, $cuser);
            $this->sendPushNotification($job, $cuser);
            return $this->createSuccessResponse($job);
        }

        // If job has already been accepted
        $language = TeHelper::fetchLanguageFromJobId($job->from_language_id);
        return $this->createFailResponse('Denna ' . $language . 'tolkning ' . $job->duration . 'min ' . $job->due . ' har redan accepterats av annan tolk. Du har inte fått denna tolkning');
    }

    /**
     * Check if the translator is already booked for the job
     */
    private function isTranslatorAlreadyBooked($job, $user)
    {
        return Job::isTranslatorAlreadyBooked($job->id, $user->id, $job->due);
    }

    /**
     * Assign the job to the translator and update job status
     */
    private function assignJobToTranslator($job, $user)
    {
        if ($job->status == 'pending' && Job::insertTranslatorJobRel($user->id, $job->id)) {
            $job->status = 'assigned';
            $job->save();
            return true;
        }
        return false;
    }

    /**
     * Send job acceptance email to the customer
     */
    private function sendAcceptanceEmail($job, $user)
    {
        $subject = 'Bekräftelse - tolk har accepterat er bokning (bokning # ' . $job->id . ')';
        $recipientEmail = !empty($job->user_email) ? $job->user_email : $user->email;
        $name = $user->name;
        
        $mailer = new AppMailer();
        $data = ['user' => $user, 'job' => $job];
        $mailer->send($recipientEmail, $name, $subject, 'emails.job-accepted', $data);
    }

    /**
     * Send push notification to the customer
     */
    private function sendPushNotification($job, $user)
    {
        $data = ['notification_type' => 'job_accepted'];
        $language = TeHelper::fetchLanguageFromJobId($job->from_language_id);
        $msg_text = [
            "en" => 'Din bokning för ' . $language . ' tolken, ' . $job->duration . 'min, ' . $job->due . ' har accepterats av en tolk. Vänligen öppna appen för att se detaljer om tolken.'
        ];

        if ($this->isNeedToSendPush($user->id)) {
            $this->sendPushNotificationToSpecificUsers([$user], $job->id, $data, $msg_text, $this->isNeedToDelayPush($user->id));
        }
    }

    /**
     * Create a success response
     */
    private function createSuccessResponse($job)
    {
        $jobs = $this->getPotentialJobs($job->user);
        return [
            'status' => 'success',
            'list' => json_encode(['jobs' => $jobs, 'job' => $job], true),
            'message' => 'Du har nu accepterat och fått bokningen för ' . TeHelper::fetchLanguageFromJobId($job->from_language_id) . 'tolk ' . $job->duration . 'min ' . $job->due
        ];
    }

    /**
     * Create a fail response with a message
     */
    private function createFailResponse($message)
    {
        return [
            'status' => 'fail',
            'message' => $message
        ];
    }


    public function cancelJobAjax($data, $user)
    {
        $response = [];
        $jobId = $data['job_id'];
        $job = Job::findOrFail($jobId);
        $translator = Job::getJobsAssignedTranslatorDetail($job);
        $cuser = $user;

        // Handle customer cancellation logic
        if ($cuser->is('customer')) {
            return $this->handleCustomerCancellation($job, $translator);
        }

        // Handle translator cancellation logic
        return $this->handleTranslatorCancellation($job, $translator);
    }

    /**
     * Handle the cancellation process for customers
     */
    private function handleCustomerCancellation($job, $translator)
    {
        $job->withdraw_at = Carbon::now();

        // Determine if cancellation is before or after 24 hours
        if ($job->withdraw_at->diffInHours($job->due) >= 24) {
            $job->status = 'withdrawbefore24';
        } else {
            $job->status = 'withdrawafter24';
        }

        $job->save();
        Event::fire(new JobWasCanceled($job));

        $this->sendCancellationNotificationToTranslator($job, $translator);

        return [
            'status' => 'success',
            'jobstatus' => 'success'
        ];
    }

    /**
     * Handle the cancellation process for translators
     */
    private function handleTranslatorCancellation($job, $translator)
    {
        if ($job->due->diffInHours(Carbon::now()) > 24) {
            return $this->processTranslatorCancellation($job, $translator);
        }

        return [
            'status' => 'fail',
            'message' => 'Du kan inte avboka en bokning som sker inom 24 timmar genom DigitalTolk. Vänligen ring på +46 73 75 86 865 och gör din avbokning over telefon. Tack!'
        ];
    }

    /**
     * Process the cancellation for a translator when the job is more than 24 hours away
     */
    private function processTranslatorCancellation($job, $translator)
    {
        $customer = $job->user()->first();
        if ($customer) {
            $this->sendCancellationNotificationToCustomer($job, $customer);
        }

        // Reset job status to pending and reassign a new translator
        $job->status = 'pending';
        $job->created_at = Carbon::now();
        $job->will_expire_at = TeHelper::willExpireAt($job->due, Carbon::now());
        $job->save();

        Job::deleteTranslatorJobRel($translator->id, $job->id);

        // Notify suitable translators
        $data = $this->jobToData($job);
        $this->sendNotificationTranslator($job, $data, $translator->id);

        return [
            'status' => 'success'
        ];
    }

    /**
     * Send cancellation notification to the translator
     */
    private function sendCancellationNotificationToTranslator($job, $translator)
    {
        if (!$translator) {
            return;
        }

        $data = [
            'notification_type' => 'job_cancelled'
        ];
        $language = TeHelper::fetchLanguageFromJobId($job->from_language_id);
        $msg_text = [
            "en" => 'Kunden har avbokat bokningen för ' . $language . 'tolk, ' . $job->duration . 'min, ' . $job->due . '. Var god och kolla dina tidigare bokningar för detaljer.'
        ];

        if ($this->isNeedToSendPush($translator->id)) {
            $users_array = [$translator];
            $this->sendPushNotificationToSpecificUsers($users_array, $job->id, $data, $msg_text, $this->isNeedToDelayPush($translator->id));
        }
    }

    /**
     * Send cancellation notification to the customer
     */
    private function sendCancellationNotificationToCustomer($job, $customer)
    {
        $data = [
            'notification_type' => 'job_cancelled'
        ];
        $language = TeHelper::fetchLanguageFromJobId($job->from_language_id);
        $msg_text = [
            "en" => 'Er ' . $language . 'tolk, ' . $job->duration . 'min ' . $job->due . ', har avbokat tolkningen. Vi letar nu efter en ny tolk som kan ersätta denne. Tack.'
        ];

        if ($this->isNeedToSendPush($customer->id)) {
            $users_array = [$customer];
            $this->sendPushNotificationToSpecificUsers($users_array, $job->id, $data, $msg_text, $this->isNeedToDelayPush($customer->id));
        }
    }


    /*Function to get the potential jobs for paid,rws,unpaid translators*/
    public function getPotentialJobs($cuser)
    {
        $cuser_meta = $cuser->userMeta;
        $job_type = 'unpaid';
        $translator_type = $cuser_meta->translator_type;
        if ($translator_type == 'professional')
            $job_type = 'paid';   /*show all jobs for professionals.*/
        else if ($translator_type == 'rwstranslator')
            $job_type = 'rws';  /* for rwstranslator only show rws jobs. */
        else if ($translator_type == 'volunteer')
            $job_type = 'unpaid';  /* for volunteers only show unpaid jobs. */

        $languages = UserLanguages::where('user_id', '=', $cuser->id)->get();
        $userlanguage = collect($languages)->pluck('lang_id')->all();
        $gender = $cuser_meta->gender;
        $translator_level = $cuser_meta->translator_level;
        /*Call the town function for checking if the job physical, then translators in one town can get job*/
        $job_ids = Job::getJobs($cuser->id, $job_type, 'pending', $userlanguage, $gender, $translator_level);
        foreach ($job_ids as $k => $job) {
            $jobuserid = $job->user_id;
            $job->specific_job = Job::assignedToPaticularTranslator($cuser->id, $job->id);
            $job->check_particular_job = Job::checkParticularJob($cuser->id, $job);
            $checktown = Job::checkTowns($jobuserid, $cuser->id);

            if($job->specific_job == 'SpecificJob')
                if ($job->check_particular_job == 'userCanNotAcceptJob')
                unset($job_ids[$k]);

            if (($job->customer_phone_type == 'no' || $job->customer_phone_type == '') && $job->customer_physical_type == 'yes' && $checktown == false) {
                unset($job_ids[$k]);
            }
        }
//        $jobs = TeHelper::convertJobIdsInObjs($job_ids);
        return $job_ids;
    }

    public function endJob($post_data)
    {
        $completeddate = date('Y-m-d H:i:s');
        $jobid = $post_data["job_id"];
        $job_detail = Job::with('translatorJobRel')->find($jobid);

        if($job_detail->status != 'started')
            return ['status' => 'success'];

        $duedate = $job_detail->due;
        $start = date_create($duedate);
        $end = date_create($completeddate);
        $diff = date_diff($end, $start);
        $interval = $diff->h . ':' . $diff->i . ':' . $diff->s;
        $job = $job_detail;
        $job->end_at = date('Y-m-d H:i:s');
        $job->status = 'completed';
        $job->session_time = $interval;

        $user = $job->user()->get()->first();
        if (!empty($job->user_email)) {
            $email = $job->user_email;
        } else {
            $email = $user->email;
        }
        $name = $user->name;
        $subject = 'Information om avslutad tolkning för bokningsnummer # ' . $job->id;
        $session_explode = explode(':', $job->session_time);
        $session_time = $session_explode[0] . ' tim ' . $session_explode[1] . ' min';
        $data = [
            'user'         => $user,
            'job'          => $job,
            'session_time' => $session_time,
            'for_text'     => 'faktura'
        ];
        $mailer = new AppMailer();
        $mailer->send($email, $name, $subject, 'emails.session-ended', $data);

        $job->save();

        $tr = $job->translatorJobRel()->where('completed_at', Null)->where('cancel_at', Null)->first();

        Event::fire(new SessionEnded($job, ($post_data['user_id'] == $job->user_id) ? $tr->user_id : $job->user_id));

        $user = $tr->user()->first();
        $email = $user->email;
        $name = $user->name;
        $subject = 'Information om avslutad tolkning för bokningsnummer # ' . $job->id;
        $data = [
            'user'         => $user,
            'job'          => $job,
            'session_time' => $session_time,
            'for_text'     => 'lön'
        ];
        $mailer = new AppMailer();
        $mailer->send($email, $name, $subject, 'emails.session-ended', $data);

        $tr->completed_at = $completeddate;
        $tr->completed_by = $post_data['user_id'];
        $tr->save();
        $response['status'] = 'success';
        return $response;
    }


    public function customerNotCall($post_data)
    {
        $completeddate = date('Y-m-d H:i:s');
        $jobid = $post_data["job_id"];
        $job_detail = Job::with('translatorJobRel')->find($jobid);
        $duedate = $job_detail->due;
        $start = date_create($duedate);
        $end = date_create($completeddate);
        $diff = date_diff($end, $start);
        $interval = $diff->h . ':' . $diff->i . ':' . $diff->s;
        $job = $job_detail;
        $job->end_at = date('Y-m-d H:i:s');
        $job->status = 'not_carried_out_customer';

        $tr = $job->translatorJobRel()->where('completed_at', Null)->where('cancel_at', Null)->first();
        $tr->completed_at = $completeddate;
        $tr->completed_by = $tr->user_id;
        $job->save();
        $tr->save();
        $response['status'] = 'success';
        return $response;
    }

    public function getAll(Request $request, $limit = null)
    {
        $requestdata = $request->all();
        $cuser = $request->__authenticatedUser;
        $consumer_type = $cuser->consumer_type;
        
        $allJobs = Job::query();

        // Common filter handling for both SUPERADMIN and regular users
        $this->applyCommonFilters($allJobs, $requestdata);

        // If the user is SUPERADMIN, apply additional filters
        if ($cuser && $cuser->user_type == env('SUPERADMIN_ROLE_ID')) {
            $this->applyAdminFilters($allJobs, $requestdata);
        } else {
            // Non-admin users
            $this->applyUserFilters($allJobs, $requestdata, $consumer_type);
        }

        // Pagination or fetching all jobs
        $allJobs->orderBy('created_at', 'desc')->with('user', 'language', 'feedback.user', 'translatorJobRel.user', 'distance');

        return $limit === 'all' ? $allJobs->get() : $allJobs->paginate(15);
    }

    /**
     * Apply common filters for both admin and regular users
     */
    private function applyCommonFilters($query, $requestdata)
    {
        if (isset($requestdata['feedback']) && $requestdata['feedback'] !== 'false') {
            $query->where('ignore_feedback', '0')
                ->whereHas('feedback', function ($q) {
                    $q->where('rating', '<=', 3);
                });
            if (isset($requestdata['count']) && $requestdata['count'] !== 'false') {
                return ['count' => $query->count()];
            }
        }

        if (isset($requestdata['lang']) && $requestdata['lang'] !== '') {
            $query->whereIn('from_language_id', $requestdata['lang']);
        }

        if (isset($requestdata['status']) && $requestdata['status'] !== '') {
            $query->whereIn('status', $requestdata['status']);
        }

        if (isset($requestdata['job_type']) && $requestdata['job_type'] !== '') {
            $query->whereIn('job_type', $requestdata['job_type']);
        }

        if (isset($requestdata['customer_email']) && $requestdata['customer_email'] !== '') {
            $user = DB::table('users')->where('email', $requestdata['customer_email'])->first();
            if ($user) {
                $query->where('user_id', '=', $user->id);
            }
        }
    }

    /**
     * Apply admin-specific filters
     */
    private function applyAdminFilters($query, $requestdata)
    {
        // Filter by job ID
        if (isset($requestdata['id']) && $requestdata['id'] !== '') {
            $this->applyJobIdFilter($query, $requestdata['id']);
        }

        // Filter by customer email (for admin)
        if (isset($requestdata['customer_email']) && count($requestdata['customer_email']) && $requestdata['customer_email'] !== '') {
            $users = DB::table('users')->whereIn('email', $requestdata['customer_email'])->get();
            if ($users) {
                $query->whereIn('user_id', collect($users)->pluck('id')->all());
            }
        }

        // Filter by translator email (for admin)
        if (isset($requestdata['translator_email']) && count($requestdata['translator_email'])) {
            $users = DB::table('users')->whereIn('email', $requestdata['translator_email'])->get();
            if ($users) {
                $allJobIDs = DB::table('translator_job_rel')->whereNull('cancel_at')->whereIn('user_id', collect($users)->pluck('id')->all())->pluck('job_id');
                $query->whereIn('id', $allJobIDs);
            }
        }

        // Apply time filters (created or due)
        $this->applyTimeFilters($query, $requestdata);
    }

    /**
     * Apply user-specific filters based on consumer type
     */
    private function applyUserFilters($query, $requestdata, $consumer_type)
    {
        // Filter by consumer type (RWS or unpaid)
        if ($consumer_type === 'RWS') {
            $query->where('job_type', '=', 'rws');
        } else {
            $query->where('job_type', '=', 'unpaid');
        }

        // Apply time filters (created or due) for users
        $this->applyTimeFilters($query, $requestdata);
    }

    /**
     * Apply job ID filter
     */
    private function applyJobIdFilter($query, $jobIds)
    {
        if (is_array($jobIds)) {
            $query->whereIn('id', $jobIds);
        } else {
            $query->where('id', $jobIds);
        }
    }

    /**
     * Apply time-based filters (created or due)
     */
    private function applyTimeFilters($query, $requestdata)
    {
        if (isset($requestdata['filter_timetype']) && $requestdata['filter_timetype'] === "created") {
            if (isset($requestdata['from']) && $requestdata['from'] !== "") {
                $query->where('created_at', '>=', $requestdata["from"]);
            }
            if (isset($requestdata['to']) && $requestdata['to'] !== "") {
                $to = $requestdata["to"] . " 23:59:00";
                $query->where('created_at', '<=', $to);
            }
            $query->orderBy('created_at', 'desc');
        }

        if (isset($requestdata['filter_timetype']) && $requestdata['filter_timetype'] === "due") {
            if (isset($requestdata['from']) && $requestdata['from'] !== "") {
                $query->where('due', '>=', $requestdata["from"]);
            }
            if (isset($requestdata['to']) && $requestdata['to'] !== "") {
                $to = $requestdata["to"] . " 23:59:00";
                $query->where('due', '<=', $to);
            }
            $query->orderBy('due', 'desc');
        }
    }


    public function alerts()
    {
        $jobs = Job::all();
        $sesJobs = [];
        $jobId = [];
        $diff = [];
        $i = 0;

        foreach ($jobs as $job) {
            $sessionTime = explode(':', $job->session_time);
            if (count($sessionTime) >= 3) {
                $diff[$i] = ($sessionTime[0] * 60) + $sessionTime[1] + ($sessionTime[2] / 60);

                if ($diff[$i] >= $job->duration) {
                    if ($diff[$i] >= $job->duration * 2) {
                        $sesJobs [$i] = $job;
                    }
                }
                $i++;
            }
        }

        foreach ($sesJobs as $job) {
            $jobId [] = $job->id;
        }

        $languages = Language::where('active', '1')->orderBy('language')->get();
        $requestdata = Request::all();
        $all_customers = DB::table('users')->where('user_type', '1')->lists('email');
        $all_translators = DB::table('users')->where('user_type', '2')->lists('email');

        $cuser = Auth::user();
        $consumer_type = TeHelper::getUsermeta($cuser->id, 'consumer_type');


        if ($cuser && $cuser->is('superadmin')) {
            $allJobs = DB::table('jobs')
                ->join('languages', 'jobs.from_language_id', '=', 'languages.id')->whereIn('jobs.id', $jobId);
            if (isset($requestdata['lang']) && $requestdata['lang'] != '') {
                $allJobs->whereIn('jobs.from_language_id', $requestdata['lang'])
                    ->where('jobs.ignore', 0);
                /*$allJobs->where('jobs.from_language_id', '=', $requestdata['lang']);*/
            }
            if (isset($requestdata['status']) && $requestdata['status'] != '') {
                $allJobs->whereIn('jobs.status', $requestdata['status'])
                    ->where('jobs.ignore', 0);
                /*$allJobs->where('jobs.status', '=', $requestdata['status']);*/
            }
            if (isset($requestdata['customer_email']) && $requestdata['customer_email'] != '') {
                $user = DB::table('users')->where('email', $requestdata['customer_email'])->first();
                if ($user) {
                    $allJobs->where('jobs.user_id', '=', $user->id)
                        ->where('jobs.ignore', 0);
                }
            }
            if (isset($requestdata['translator_email']) && $requestdata['translator_email'] != '') {
                $user = DB::table('users')->where('email', $requestdata['translator_email'])->first();
                if ($user) {
                    $allJobIDs = DB::table('translator_job_rel')->where('user_id', $user->id)->lists('job_id');
                    $allJobs->whereIn('jobs.id', $allJobIDs)
                        ->where('jobs.ignore', 0);
                }
            }
            if (isset($requestdata['filter_timetype']) && $requestdata['filter_timetype'] == "created") {
                if (isset($requestdata['from']) && $requestdata['from'] != "") {
                    $allJobs->where('jobs.created_at', '>=', $requestdata["from"])
                        ->where('jobs.ignore', 0);
                }
                if (isset($requestdata['to']) && $requestdata['to'] != "") {
                    $to = $requestdata["to"] . " 23:59:00";
                    $allJobs->where('jobs.created_at', '<=', $to)
                        ->where('jobs.ignore', 0);
                }
                $allJobs->orderBy('jobs.created_at', 'desc');
            }
            if (isset($requestdata['filter_timetype']) && $requestdata['filter_timetype'] == "due") {
                if (isset($requestdata['from']) && $requestdata['from'] != "") {
                    $allJobs->where('jobs.due', '>=', $requestdata["from"])
                        ->where('jobs.ignore', 0);
                }
                if (isset($requestdata['to']) && $requestdata['to'] != "") {
                    $to = $requestdata["to"] . " 23:59:00";
                    $allJobs->where('jobs.due', '<=', $to)
                        ->where('jobs.ignore', 0);
                }
                $allJobs->orderBy('jobs.due', 'desc');
            }

            if (isset($requestdata['job_type']) && $requestdata['job_type'] != '') {
                $allJobs->whereIn('jobs.job_type', $requestdata['job_type'])
                    ->where('jobs.ignore', 0);
                /*$allJobs->where('jobs.job_type', '=', $requestdata['job_type']);*/
            }
            $allJobs->select('jobs.*', 'languages.language')
                ->where('jobs.ignore', 0)
                ->whereIn('jobs.id', $jobId);

            $allJobs->orderBy('jobs.created_at', 'desc');
            $allJobs = $allJobs->paginate(15);
        }

        return ['allJobs' => $allJobs, 'languages' => $languages, 'all_customers' => $all_customers, 'all_translators' => $all_translators, 'requestdata' => $requestdata];
    }

    public function userLoginFailed()
    {
        $throttles = Throttles::where('ignore', 0)->with('user')->paginate(15);

        return ['throttles' => $throttles];
    }

    public function bookingExpireNoAccepted()
    {
        $languages = Language::where('active', '1')->orderBy('language')->get();
        $requestdata = Request::all();
        $all_customers = DB::table('users')->where('user_type', '1')->lists('email');
        $all_translators = DB::table('users')->where('user_type', '2')->lists('email');

        $cuser = Auth::user();
        $consumer_type = TeHelper::getUsermeta($cuser->id, 'consumer_type');


        if ($cuser && ($cuser->is('superadmin') || $cuser->is('admin'))) {
            $allJobs = DB::table('jobs')
                ->join('languages', 'jobs.from_language_id', '=', 'languages.id')
                ->where('jobs.ignore_expired', 0);
            if (isset($requestdata['lang']) && $requestdata['lang'] != '') {
                $allJobs->whereIn('jobs.from_language_id', $requestdata['lang'])
                    ->where('jobs.status', 'pending')
                    ->where('jobs.ignore_expired', 0)
                    ->where('jobs.due', '>=', Carbon::now());
                /*$allJobs->where('jobs.from_language_id', '=', $requestdata['lang']);*/
            }
            if (isset($requestdata['status']) && $requestdata['status'] != '') {
                $allJobs->whereIn('jobs.status', $requestdata['status'])
                    ->where('jobs.status', 'pending')
                    ->where('jobs.ignore_expired', 0)
                    ->where('jobs.due', '>=', Carbon::now());
                /*$allJobs->where('jobs.status', '=', $requestdata['status']);*/
            }
            if (isset($requestdata['customer_email']) && $requestdata['customer_email'] != '') {
                $user = DB::table('users')->where('email', $requestdata['customer_email'])->first();
                if ($user) {
                    $allJobs->where('jobs.user_id', '=', $user->id)
                        ->where('jobs.status', 'pending')
                        ->where('jobs.ignore_expired', 0)
                        ->where('jobs.due', '>=', Carbon::now());
                }
            }
            if (isset($requestdata['translator_email']) && $requestdata['translator_email'] != '') {
                $user = DB::table('users')->where('email', $requestdata['translator_email'])->first();
                if ($user) {
                    $allJobIDs = DB::table('translator_job_rel')->where('user_id', $user->id)->lists('job_id');
                    $allJobs->whereIn('jobs.id', $allJobIDs)
                        ->where('jobs.status', 'pending')
                        ->where('jobs.ignore_expired', 0)
                        ->where('jobs.due', '>=', Carbon::now());
                }
            }
            if (isset($requestdata['filter_timetype']) && $requestdata['filter_timetype'] == "created") {
                if (isset($requestdata['from']) && $requestdata['from'] != "") {
                    $allJobs->where('jobs.created_at', '>=', $requestdata["from"])
                        ->where('jobs.status', 'pending')
                        ->where('jobs.ignore_expired', 0)
                        ->where('jobs.due', '>=', Carbon::now());
                }
                if (isset($requestdata['to']) && $requestdata['to'] != "") {
                    $to = $requestdata["to"] . " 23:59:00";
                    $allJobs->where('jobs.created_at', '<=', $to)
                        ->where('jobs.status', 'pending')
                        ->where('jobs.ignore_expired', 0)
                        ->where('jobs.due', '>=', Carbon::now());
                }
                $allJobs->orderBy('jobs.created_at', 'desc');
            }
            if (isset($requestdata['filter_timetype']) && $requestdata['filter_timetype'] == "due") {
                if (isset($requestdata['from']) && $requestdata['from'] != "") {
                    $allJobs->where('jobs.due', '>=', $requestdata["from"])
                        ->where('jobs.status', 'pending')
                        ->where('jobs.ignore_expired', 0)
                        ->where('jobs.due', '>=', Carbon::now());
                }
                if (isset($requestdata['to']) && $requestdata['to'] != "") {
                    $to = $requestdata["to"] . " 23:59:00";
                    $allJobs->where('jobs.due', '<=', $to)
                        ->where('jobs.status', 'pending')
                        ->where('jobs.ignore_expired', 0)
                        ->where('jobs.due', '>=', Carbon::now());
                }
                $allJobs->orderBy('jobs.due', 'desc');
            }

            if (isset($requestdata['job_type']) && $requestdata['job_type'] != '') {
                $allJobs->whereIn('jobs.job_type', $requestdata['job_type'])
                    ->where('jobs.status', 'pending')
                    ->where('jobs.ignore_expired', 0)
                    ->where('jobs.due', '>=', Carbon::now());
                /*$allJobs->where('jobs.job_type', '=', $requestdata['job_type']);*/
            }
            $allJobs->select('jobs.*', 'languages.language')
                ->where('jobs.status', 'pending')
                ->where('ignore_expired', 0)
                ->where('jobs.due', '>=', Carbon::now());

            $allJobs->orderBy('jobs.created_at', 'desc');
            $allJobs = $allJobs->paginate(15);

        }
        return ['allJobs' => $allJobs, 'languages' => $languages, 'all_customers' => $all_customers, 'all_translators' => $all_translators, 'requestdata' => $requestdata];
    }

    public function ignoreExpiring($id)
    {
        $job = Job::find($id);
        $job->ignore = 1;
        $job->save();
        return ['success', 'Changes saved'];
    }

    public function ignoreExpired($id)
    {
        $job = Job::find($id);
        $job->ignore_expired = 1;
        $job->save();
        return ['success', 'Changes saved'];
    }

    public function ignoreThrottle($id)
    {
        $throttle = Throttles::find($id);
        $throttle->ignore = 1;
        $throttle->save();
        return ['success', 'Changes saved'];
    }

    public function reopen($request)
    {
        $jobId = $request['jobid'];
        $userId = $request['userid'];

        // Retrieve the job data and handle cases where it might not exist
        $job = Job::find($jobId);
        if (!$job) {
            return ["Job not found!"];
        }

        // Prepare common data for updating the job
        $data = $this->prepareCancelJobData($job, $userId, $jobId);
        
        // Handle job status and update or create job accordingly
        $newJobId = $this->handleJobStatus($job, $jobId, $data);
        
        // Handle the cancellation and re-insertion of translators
        $this->handleTranslatorCancellation($jobId, $data['cancel_at']);

        // Send notification
        $this->sendNotificationByAdminCancelJob($newJobId);

        return ["Tolk cancelled!"];
    }

    /**
     * Prepare the common data to be used for updating or creating a job.
     *
     * @param Job $job
     * @param int $userId
     * @param int $jobId
     * @return array
     */
    private function prepareCancelJobData($job, $userId, $jobId)
    {
        $createdAt = Carbon::now();
        $willExpireAt = TeHelper::willExpireAt($job->due, $createdAt);

        return [
            'created_at' => $createdAt,
            'will_expire_at' => $willExpireAt,
            'updated_at' => $createdAt,
            'user_id' => $userId,
            'job_id' => $jobId,
            'cancel_at' => $createdAt,
        ];
    }

    /**
     * Handle job status and decide whether to update or create a new job.
     *
     * @param Job $job
     * @param int $jobId
     * @param array $data
     * @return int
     */
    private function handleJobStatus($job, $jobId, $data)
    {
        if ($job->status != 'timedout') {
            // Update the existing job if the status is not 'timedout'
            Job::where('id', $jobId)->update(['status' => 'pending'] + $data);
            return $jobId;
        } else {
            // Create a new job if the status is 'timedout'
            $newJobData = array_merge($job->toArray(), $data, [
                'status' => 'pending',
                'admin_comments' => 'This booking is a reopening of booking #' . $jobId,
                'cust_16_hour_email' => 0,
                'cust_48_hour_email' => 0,
            ]);
            $newJob = Job::create($newJobData);
            return $newJob->id;
        }
    }

    /**
     * Handle the cancellation of translators and create new translator record.
     *
     * @param int $jobId
     * @param string $cancelAt
     */
    private function handleTranslatorCancellation($jobId, $cancelAt)
    {
        // Cancel existing translators
        Translator::where('job_id', $jobId)->whereNull('cancel_at')->update(['cancel_at' => $cancelAt]);

        // Create new translator entry
        $translatorData = [
            'job_id' => $jobId,
            'cancel_at' => $cancelAt,
            'created_at' => Carbon::now(),
        ];
        Translator::create($translatorData);
    }


    /**
     * Convert number of minutes to hour and minute variant
     * @param  int $time   
     * @param  string $format 
     * @return string         
     */
    private function convertToHoursMins($time, $format = '%02dh %02dmin')
    {
        if ($time < 60) {
            return $time . 'min';
        } else if ($time == 60) {
            return '1h';
        }

        $hours = floor($time / 60);
        $minutes = ($time % 60);
        
        return sprintf($format, $hours, $minutes);
    }

}