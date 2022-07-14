<?php

namespace DTApi\Http\Controllers;

use Illuminate\Http\Request;
use DTApi\Repository\JobRepositoryInterface;

/**
 * Class JobController
 * @package DTApi\Http\Controllers
 */
class JobController extends Controller
{

    /**
     * @var JobRepository
     */
    protected $repository;

    /**
     * JobController constructor.
     * @param JobRepository $JobRepository
     */
    public function __construct(JobRepositoryInterface $JobRepository)
    {
        $this->repository = $JobRepository;
    }

    /**
     * @param Request $request
     * @return mixed
     */
    public function index(Request $request)
    {
        if($user_id = $request->get('user_id')) {
            $response = $this->repository->getJobsOfGivenUser($user_id);
        }
        elseif($request->__authenticatedUser->user_type == env('ADMIN_ROLE_ID') || $request->__authenticatedUser->user_type == env('SUPERADMIN_ROLE_ID'))
        {
            $response = $this->repository->getAll($request);
        }
        return response($response);
    }
    /**
     * @param $id
     * @return mixed
     */
    public function show($id)
    {
        $job = $this->repository->with('translatorJobRel.user')->find($id);
        return response($job);
    }

    /**
     * @param Request $request
     * @return mixed
     */
    public function store(Request $request)
    {
        $data = $request->all();
        $response = $this->repository->store($request->__authenticatedUser, $data);
        return response($response);
    }

    /**
     * @param $id
     * @param Request $request
     * @return mixed
     */
    public function update($id, Request $request)
    {
        $data = $request->all();
        $cuser = $request->__authenticatedUser;
        $response = $this->repository->updateJob($id, array_except($data, ['_token', 'submit']), $cuser);
        if (isset($response['Updated'])) {
            return response($response);
        }
        if($response['dateChanged']) Event::fire('job_date_changed', $user);
        if($response['translatorChanged']) Event::fire('job_translator_changed', $user);
        if($response['langChanged']) Event::fire('job_language_changed', $user);

        return response($response);
    }

}
