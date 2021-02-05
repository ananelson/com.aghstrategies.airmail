<?php

use CRM_Airmail_Utils as E;

// Borrowed lots from Sendgrid backend

class CRM_Airmail_Backend_SmtpCom implements CRM_Airmail_Backend {

  public function processInput($input) {
    return json_decode($input);
  }

  public function validateMessages($events) {
    return is_array($events);
  }

  public function processMessages($events) {
    foreach ($events as $event) {
      Civi::log()->debug("[Airmail] Debugging all messages received\n" . json_encode($event));

      if (empty($event->civimail_source)) {
        Civi::log()->debug("[Airmail] no civimail_source for this message");
        // Something that wasn't sent through a CiviMail job
        continue;
      }

      $mailingJobInfo = E::parseSourceString($event->civimail_source);

      $params = [
        'job_id' => $mailingJobInfo['job_id'],
        'event_queue_id' => $mailingJobInfo['event_queue_id'],
        'hash' => $mailingJobInfo['hash'],
      ];


      // TODO automate setting up callbacks?
      // Available events documented at https://www.smtp.com/resources/api-documentation/ 
      // These must be individually subscribed to via a callback.

      switch ($event->event_label) {
        case 'open':
          CRM_Airmail_EventAction::open($params);
          break;

        case 'bounce_back':
        case 'bounce':
        case 'failed': // untested
          $params['body'] = $event->resp_msg;
          CRM_Airmail_EventAction::bounce($params);
          break;

        case 'delivery':
            break;

        default:
          Civi::log()->debug("[Airmail] Unhandled event type! " . $event->event_label);
      }
    }
  }

  /**
   * Called by hook_civicrm_alterMailParams
   *
   * @param array $params
   *   The mailing params
   * @param string $context
   *   The mailing context.
   */
  public function alterMailParams(&$params, $context) {
    Civi::log()->debug(
      "[Airmail] in alterMailParams\ncontext: " . print_r($context, TRUE) .
      "\n[Airmail] subject: " . $params['Subject']
    );
    if (empty($params['Return-Path']) || !in_array($context, ['civimail', 'flexmailer'])) {
      // If the context is missing or there is no return path set, do nothing.
      return;
    }
    $header = ['unique_args' => ['civimail_source' => $params['Return-Path']]];
    $params['X-SMTPAPI'] = trim(substr(preg_replace('/(.{1,70})(,|:|\})/', '$1$2' . "\n", 'X-SMTPAPI: ' . json_encode($header)), 11));
  }
}
