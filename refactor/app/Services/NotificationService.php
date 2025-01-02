<?php

namespace app\Services;
use DTApi\Helpers\SendSMSHelper;

class NotificationService
{
    protected $mailer;
    protected $pushService;
    protected $logger;

    public function __construct(Mailer $mailer, PushNotificationService $pushService, Logger $logger)
    {
        $this->mailer = $mailer;
        $this->pushService = $pushService;
        $this->logger = $logger;
    }

    // Send session start reminders
    public function sendSessionStartRemindNotification($user, $job, $language, $due, $duration)
    {
        $this->logger->info('Sending session start reminder for Job ID ' . $job->id);
        $due_explode = explode(' ', $due);
        $msg_text = $this->generateMessageText($job, $language, $due_explode, $duration);
        
        if ($this->shouldSendPush($user->id)) {
            $this->sendPushNotification($user, $job, $msg_text);
        }
    }

    // Helper to generate the notification message
    private function generateMessageText($job, $language, $due_explode, $duration)
    {
        if ($job->customer_physical_type == 'yes') {
            return "Reminder: You have an in-person {$language} interpretation job at {$job->town} at {$due_explode[1]} on {$due_explode[0]} lasting {$duration} mins. Don't forget to provide feedback!";
        } else {
            return "Reminder: You have a phone-based {$language} interpretation job at {$due_explode[1]} on {$due_explode[0]} lasting {$duration} mins. Don't forget to provide feedback!";
        }
    }

    // Check if push notifications should be sent
    private function shouldSendPush($userId)
    {
        // Logic to check if push notification should be sent
        return true; // Simplified for demonstration
    }

    // Send push notification via PushService
    private function sendPushNotification($user, $job, $msg_text)
    {
        $data = [
            'notification_type' => 'session_start_remind',
            'job_id' => $job->id
        ];
        $this->pushService->sendPushNotificationToUser($user, $job->id, $data, $msg_text);
    }

    // Send email notifications for job status changes or session completions
    public function sendChangedTranslatorNotification($job, $current_translator, $new_translator)
    {
        $user = $job->user;

        $this->sendEmail($user, $job, 'emails.job-changed-translator-customer', 'Job Changed: Translator Assigned', [
            'user' => $user,
            'job' => $job
        ]);

        // Send email for current translator (if exists)
        if ($current_translator) {
            $this->sendEmail($current_translator->user, $job, 'emails.job-changed-translator-old-translator', 'Job Changed: Translator Removed', [
                'user' => $current_translator->user,
                'job' => $job
            ]);
        }

        // Send email for new translator
        if ($new_translator) {
            $this->sendEmail($new_translator->user, $job, 'emails.job-changed-translator-new-translator', 'Job Changed: New Translator Assigned', [
                'user' => $new_translator->user,
                'job' => $job
            ]);
        }
    }

    private function sendEmail($user, $job, $template, $subject, $data)
    {
        $this->mailer->send($user->email, $user->name, $subject, $template, $data);
    }

     /**
     * @param $job
     * @param $old_time
     */
    public function sendChangedDateNotification($job, $old_time)
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
            'old_time' => $old_time
        ];
        $this->mailer->send($email, $name, $subject, 'emails.job-changed-date', $data);

        $translator = Job::getJobsAssignedTranslatorDetail($job);
        $data = [
            'user'     => $translator,
            'job'      => $job,
            'old_time' => $old_time
        ];
        $this->mailer->send($translator->email, $translator->name, $subject, 'emails.job-changed-date', $data);

    }


    /**
     * Send session end emails to the customer.
     *
     * @param $user
     * @param $job
     * @param $sessionTime
     */
    public function sendSessionEndEmails($user, $job, $sessionTime)
    {
        $email = $job->user_email ?: $user->email;
        $name = $user->name;

        $dataEmail = [
            'user'         => $user,
            'job'          => $job,
            'session_time' => $sessionTime,
            'for_text'     => 'faktura'
        ];

        $subject = 'Information om avslutad tolkning för bokningsnummer #' . $job->id;
        $this->mailer->send($email, $name, $subject, 'emails.session-ended', $dataEmail);
    }

      /**
     * Send session end emails to the translator.
     *
     * @param $job
     * @param $sessionTime
     */
    public function sendTranslatorSessionEndEmails($job, $sessionTime)
    {
        $user = $job->translatorJobRel->where('completed_at', null)->where('cancel_at', null)->first();
        $email = $user->user->email;
        $name = $user->user->name;

        $dataEmail = [
            'user'         => $user,
            'job'          => $job,
            'session_time' => $sessionTime,
            'for_text'     => 'lön'
        ];

        $subject = 'Information om avslutad tolkning för bokningsnummer # ' . $job->id;
        $this->mailer->send($email, $name, $subject, 'emails.session-ended', $dataEmail);
    }


    /**
     * Send an SMS to a translator.
     *
     * @param User $translator
     * @param string $message
     */
    public function sendSMS(User $translator, string $message)
    {
        $status = SendSMSHelper::send(env('SMS_NUMBER'), $translator->mobile, $message);
        Log::info('Sent SMS to ' . $translator->email . ' (' . $translator->mobile . '), status: ' . print_r($status, true));
    }


}
