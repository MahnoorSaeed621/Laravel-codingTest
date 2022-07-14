<?php 

namespace DTApi\Providers; 

use DTApi\Repository\EloquentRepositoryInterface; 
use DTApi\Repository\JobRepositoryInterface; 
use DTApi\Repository\Eloquent\JobRepository; 
use DTApi\Repository\Eloquent\BaseRepository; 
use Illuminate\Support\ServiceProvider; 

/** 
* Class RepositoryServiceProvider 
* @package App\Providers 
*/ 
class RepositoryServiceProvider extends ServiceProvider 
{ 
   /** 
    * Register services. 
    * 
    * @return void  
    */ 
   public function register() 
   { 
       $this->app->bind(EloquentRepositoryInterface::class, BaseRepository::class);
       $this->app->bind(JobRepositoryInterface::class, JobRepository::class);
   }
}