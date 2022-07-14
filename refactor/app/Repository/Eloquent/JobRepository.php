<?php
namespace DTApi\Repository\Eloquent;

use DTApi\Models\Job;
use DTApi\Models\User;
use DTApi\Repository\JobRepositoryInterface;

class JobRepository extends BaseRepository implements JobRepositoryInterface
{

    /**
     * @var Model
     */
    protected $model;

    /**
     * @param Model $model
     */
    public function __construct(Job $model)
    {
        $this->model = $model;
    }

    /**
     * @param $user_id
     * @return array
     */
    //Previously named as getUsersJobs
    public function getJobsOfGivenUser($user_id)
    {
        $cuser = User::find($user_id);
        $usertype = '';
        $emergencyJobs = $noramlJobs = array();
        if ($cuser && $cuser->is('customer'))
        {
            $jobs = Job::getCustomerJobs($cuser->id);
            $usertype = 'customer';
        }
        elseif ($cuser && $cuser->is('translator'))
        {
            $jobs = Job::getTranslatorJobs($cuser->id, 'new');
            $usertype = 'translator';
        }

        if (!$jobs)
        {
            return ['emergencyJobs' => $emergencyJobs, 'noramlJobs' => $noramlJobs, 'cuser' => $cuser, 'usertype' => $usertype];
        }
        $jobs = $jobs->pluck('jobs')
            ->all();
        foreach ($jobs as $jobitem)
        {
            if ($jobitem->immediate == 'yes')
            {
                $emergencyJobs[] = $jobitem;
            }
            else
            {
                $noramlJobs[] = $jobitem;
            }
        }
        $noramlJobs = collect($noramlJobs)->each(function ($item, $key) use ($user_id)
        {
            $item['usercheck'] = Job::checkParticularJob($user_id, $item);
        })->sortBy('due')
            ->all();

        return ['emergencyJobs' => $emergencyJobs, 'noramlJobs' => $noramlJobs, 'cuser' => $cuser, 'usertype' => $usertype];
    }

    public function getAll(Request $request, $limit = null)
    {
        $requestdata = $request->all();
        $cuser = $request->__authenticatedUser;
        $consumer_type = $cuser->consumer_type;

        if ($cuser && $cuser->user_type == env('SUPERADMIN_ROLE_ID'))
        {
            $allJobs = $this->getSuperAdminJobs($request, $limit);
        }
        else
        {
            $allJobs = $this->getNonAdminJobs($request, $limit);
        }
        return $allJobs;
    }

    /**
     * @param $user
     * @param $data
     * @return mixed
     */
    public function store($user, $data)
    {
        $response = isValidJobData($user, $data);
        if ($response['status'] === 'fail')
        {
            return $response;
        }
        $response = $this->formatJobDataBeforeSaving($user, $data);
        $cuser = $user;

        $job = $cuser->jobs()
            ->create($data);

        $response['status'] = 'success';
        $response['id'] = $job->id;

        return $response;
    }

    /**
     * @param $id
     * @param $data
     * @return mixed
     */
    public function updateJob($id, $data, $cuser)
    {
        $job = Job::find($id);
        $current_translator = Job::getCurrentTranslator();
        $langChanged = false;
        $changeTranslator = TranslatorRepository::updateTranslator($current_translator, $data, $job);
        $job->due =  $data['due'];
        $job->from_language_id = $data['from_language_id'];       

        $changeStatus = TeHelper::changeStatus($job, $data, $changeTranslator['translatorChanged']);
        if ($changeStatus['statusChanged'])
            $log_data[] = $changeStatus['log_data'];

        $job->admin_comments = $data['admin_comments'];
        $job->reference = $data['reference'];
        $job->save();
        if ($job->due <= Carbon::now()) {
            return ['Updated'];
        } else {
            $response = [];
            $job->save();
            if($job->due !== $data['due']) $response['dateChanged'] = true;
            if($changeTranslator['translatorChanged']) $response['translatorChanged'] = true;
            if($job->from_language_id !== $data['from_language_id']) $response['langChanged'] = true;
            return $response;
        }
    }

    private function isValidJobData($user, $data)
    {
        $response['status'] = 'fail';
        if ($user->user_type !== env('CUSTOMER_ROLE_ID'))
        {
            $response['message'] = "Translator can not create booking";
            return $response;
        }

        $response['message'] = "Du måste fylla in alla fält";
        $mustHaveFields = array(
            'from_language_id',
            'duration'
        );
        foreach ($mustHaveFields as $field)
        {
            if (!isset($data[$field]))
            {
                $response['field_name'] = $field;
                return $response;
            }
        }

        if ($data['immediate'] == 'no')
        {
            if (isset($data['due_date']) && $data['due_date'] == '')
            {
                $response['field_name'] = "due_date";
                return $response;
            }
            if (isset($data['due_time']) && $data['due_time'] == '')
            {
                $response['field_name'] = "due_time";
                return $response;
            }
            if (!isset($data['customer_phone_type']) && !isset($data['customer_physical_type']))
            {
                $response['field_name'] = "customer_phone_type";
                return $response;
            }
            if (isset($data['duration']) && $data['duration'] == '')
            {
                $response['field_name'] = "duration";
                return $response;
            }
        }
        $response['status'] = '';
    }

    private function formatJobDataBeforeSaving($user, &$data)
    {
        $immediatetime = 5;
        $consumer_type = $user
            ->userMeta->consumer_type;
        $data['customer_phone_type'] = (isset($data['customer_phone_type'])) ? 'yes' : 'no';
        $data['customer_physical_type'] = (isset($data['customer_physical_type'])) ? 'yes' : 'no';
        $response['customer_physical_type'] = (isset($data['customer_physical_type'])) ? 'yes' : 'no';

        if ($data['immediate'] == 'yes')
        {
            $due_carbon = Carbon::now()->addMinute($immediatetime);
            $data['due'] = $due_carbon->format('Y-m-d H:i:s');
            $data['immediate'] = 'yes';
            $data['customer_phone_type'] = 'yes';
            $response['type'] = 'immediate';

        }
        else
        {
            $due = $data['due_date'] . " " . $data['due_time'];
            $response['type'] = 'regular';
            $due_carbon = Carbon::createFromFormat('m/d/Y H:i', $due);
            $data['due'] = $due_carbon->format('Y-m-d H:i:s');
            if ($due_carbon->isPast())
            {
                $response['status'] = 'fail';
                $response['message'] = "Can't create booking in past";
                return $response;
            }
        }
        $data['gender'] = (in_array('male', $data['job_for'])) ? 'male' : 'female';

        if (in_array('normal', $data['job_for']))
        {
            $data['certified'] = 'normal';
        }
        else if (in_array('certified', $data['job_for']))
        {
            $data['certified'] = 'yes';
        }
        else if (in_array('certified_in_law', $data['job_for']))
        {
            $data['certified'] = 'law';
        }
        else if (in_array('certified_in_helth', $data['job_for']))
        {
            $data['certified'] = 'health';
        }

        if (in_array('normal', $data['job_for']) && in_array('certified', $data['job_for']))
        {
            $data['certified'] = 'both';
        }
        else if (in_array('normal', $data['job_for']) && in_array('certified_in_law', $data['job_for']))
        {
            $data['certified'] = 'n_law';
        }
        else if (in_array('normal', $data['job_for']) && in_array('certified_in_helth', $data['job_for']))
        {
            $data['certified'] = 'n_health';
        }
        if ($consumer_type == 'rwsconsumer') $data['job_type'] = 'rws';
        else if ($consumer_type == 'ngo') $data['job_type'] = 'unpaid';
        else if ($consumer_type == 'paid') $data['job_type'] = 'paid';
        $data['b_created_at'] = date('Y-m-d H:i:s');
        if (isset($due)) $data['will_expire_at'] = TeHelper::willExpireAt($due, $data['b_created_at']);
        $data['by_admin'] = isset($data['by_admin']) ? $data['by_admin'] : 'no';
        return $response;
    }

    private function getSuperAdminJobs(Request $request, $limit = null)
    {
        $allJobs = Job::query();

        if (isset($requestdata['feedback']) && $requestdata['feedback'] != 'false')
        {
            $allJobs->where('ignore_feedback', '0');
            $allJobs->whereHas('feedback', function ($q)
            {
                $q->where('rating', '<=', '3');
            });
            if (isset($requestdata['count']) && $requestdata['count'] != 'false') return ['count' => $allJobs->count() ];
        }

        if (isset($requestdata['id']) && $requestdata['id'] != '')
        {
            if (is_array($requestdata['id'])) $allJobs->whereIn('id', $requestdata['id']);
            else $allJobs->where('id', $requestdata['id']);
            $requestdata = array_only($requestdata, ['id']);
        }

        if (isset($requestdata['lang']) && $requestdata['lang'] != '')
        {
            $allJobs->whereIn('from_language_id', $requestdata['lang']);
        }
        if (isset($requestdata['status']) && $requestdata['status'] != '')
        {
            $allJobs->whereIn('status', $requestdata['status']);
        }
        if (isset($requestdata['expired_at']) && $requestdata['expired_at'] != '')
        {
            $allJobs->where('expired_at', '>=', $requestdata['expired_at']);
        }
        if (isset($requestdata['will_expire_at']) && $requestdata['will_expire_at'] != '')
        {
            $allJobs->where('will_expire_at', '>=', $requestdata['will_expire_at']);
        }
        if (isset($requestdata['customer_email']) && count($requestdata['customer_email']) && $requestdata['customer_email'] != '')
        {
            $users = DB::table('users')->whereIn('email', $requestdata['customer_email'])->get();
            if ($users)
            {
                $allJobs->whereIn('user_id', collect($users)->pluck('id')
                    ->all());
            }
        }
        if (isset($requestdata['translator_email']) && count($requestdata['translator_email']))
        {
            $users = DB::table('users')->whereIn('email', $requestdata['translator_email'])->get();
            if ($users)
            {
                $allJobIDs = DB::table('translator_job_rel')->whereNull('cancel_at')
                    ->whereIn('user_id', collect($users)->pluck('id')
                    ->all())
                    ->lists('job_id');
                $allJobs->whereIn('id', $allJobIDs);
            }
        }
        if (isset($requestdata['filter_timetype']) && $requestdata['filter_timetype'] == "created")
        {
            if (isset($requestdata['from']) && $requestdata['from'] != "")
            {
                $allJobs->where('created_at', '>=', $requestdata["from"]);
            }
            if (isset($requestdata['to']) && $requestdata['to'] != "")
            {
                $to = $requestdata["to"] . " 23:59:00";
                $allJobs->where('created_at', '<=', $to);
            }
            $allJobs->orderBy('created_at', 'desc');
        }
        if (isset($requestdata['filter_timetype']) && $requestdata['filter_timetype'] == "due")
        {
            if (isset($requestdata['from']) && $requestdata['from'] != "")
            {
                $allJobs->where('due', '>=', $requestdata["from"]);
            }
            if (isset($requestdata['to']) && $requestdata['to'] != "")
            {
                $to = $requestdata["to"] . " 23:59:00";
                $allJobs->where('due', '<=', $to);
            }
            $allJobs->orderBy('due', 'desc');
        }

        if (isset($requestdata['job_type']) && $requestdata['job_type'] != '')
        {
            $allJobs->whereIn('job_type', $requestdata['job_type']);
            /*$allJobs->where('jobs.job_type', '=', $requestdata['job_type']);*/
        }

        if (isset($requestdata['physical']))
        {
            $allJobs->where('customer_physical_type', $requestdata['physical']);
            $allJobs->where('ignore_physical', 0);
        }

        if (isset($requestdata['phone']))
        {
            $allJobs->where('customer_phone_type', $requestdata['phone']);
            if (isset($requestdata['physical'])) $allJobs->where('ignore_physical_phone', 0);
        }

        if (isset($requestdata['flagged']))
        {
            $allJobs->where('flagged', $requestdata['flagged']);
            $allJobs->where('ignore_flagged', 0);
        }

        if (isset($requestdata['distance']) && $requestdata['distance'] == 'empty')
        {
            $allJobs->whereDoesntHave('distance');
        }

        if (isset($requestdata['salary']) && $requestdata['salary'] == 'yes')
        {
            $allJobs->whereDoesntHave('user.salaries');
        }

        if (isset($requestdata['count']) && $requestdata['count'] == 'true')
        {
            $allJobs = $allJobs->count();

            return ['count' => $allJobs];
        }

        if (isset($requestdata['consumer_type']) && $requestdata['consumer_type'] != '')
        {
            $allJobs->whereHas('user.userMeta', function ($q) use ($requestdata)
            {
                $q->where('consumer_type', $requestdata['consumer_type']);
            });
        }

        if (isset($requestdata['booking_type']))
        {
            if ($requestdata['booking_type'] == 'physical') $allJobs->where('customer_physical_type', 'yes');
            if ($requestdata['booking_type'] == 'phone') $allJobs->where('customer_phone_type', 'yes');
        }

        $allJobs->orderBy('created_at', 'desc');
        $allJobs->with('user', 'language', 'feedback.user', 'translatorJobRel.user', 'distance');
        if ($limit == 'all') $allJobs = $allJobs->get();
        else $allJobs = $allJobs->paginate($limit);

        return $allJobs;

    }
    private function getNonAdminJobs(Request $request, $limit = null)
    {

        $allJobs = Job::query();

        if (isset($requestdata['id']) && $requestdata['id'] != '')
        {
            $allJobs->where('id', $requestdata['id']);
            $requestdata = array_only($requestdata, ['id']);
        }

        if ($consumer_type == 'RWS')
        {
            $allJobs->where('job_type', '=', 'rws');
        }
        else
        {
            $allJobs->where('job_type', '=', 'unpaid');
        }
        if (isset($requestdata['feedback']) && $requestdata['feedback'] != 'false')
        {
            $allJobs->where('ignore_feedback', '0');
            $allJobs->whereHas('feedback', function ($q)
            {
                $q->where('rating', '<=', '3');
            });
            if (isset($requestdata['count']) && $requestdata['count'] != 'false') return ['count' => $allJobs->count() ];
        }

        if (isset($requestdata['lang']) && $requestdata['lang'] != '')
        {
            $allJobs->whereIn('from_language_id', $requestdata['lang']);
        }
        if (isset($requestdata['status']) && $requestdata['status'] != '')
        {
            $allJobs->whereIn('status', $requestdata['status']);
        }
        if (isset($requestdata['job_type']) && $requestdata['job_type'] != '')
        {
            $allJobs->whereIn('job_type', $requestdata['job_type']);
        }
        if (isset($requestdata['customer_email']) && $requestdata['customer_email'] != '')
        {
            $user = DB::table('users')->where('email', $requestdata['customer_email'])->first();
            if ($user)
            {
                $allJobs->where('user_id', '=', $user->id);
            }
        }
        if (isset($requestdata['filter_timetype']) && $requestdata['filter_timetype'] == "created")
        {
            if (isset($requestdata['from']) && $requestdata['from'] != "")
            {
                $allJobs->where('created_at', '>=', $requestdata["from"]);
            }
            if (isset($requestdata['to']) && $requestdata['to'] != "")
            {
                $to = $requestdata["to"] . " 23:59:00";
                $allJobs->where('created_at', '<=', $to);
            }
            $allJobs->orderBy('created_at', 'desc');
        }
        if (isset($requestdata['filter_timetype']) && $requestdata['filter_timetype'] == "due")
        {
            if (isset($requestdata['from']) && $requestdata['from'] != "")
            {
                $allJobs->where('due', '>=', $requestdata["from"]);
            }
            if (isset($requestdata['to']) && $requestdata['to'] != "")
            {
                $to = $requestdata["to"] . " 23:59:00";
                $allJobs->where('due', '<=', $to);
            }
            $allJobs->orderBy('due', 'desc');
        }

        $allJobs->orderBy('created_at', 'desc');
        $allJobs->with('user', 'language', 'feedback.user', 'translatorJobRel.user', 'distance');
        if ($limit == 'all') $allJobs = $allJobs->get();
        else $allJobs = $allJobs->paginate($limit);
    }
}

