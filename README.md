# Laravel-codingTest

Current code is not following SOILD principles and is not reuseable or extendable infact it had mixed up Services and Repository Design Pattern.
## Implementing Repository Design Pattern
Repository pattern helps encapsulating logic required to access the data in scalable and reuseable way as well as handles Dependency Injection. It makes code more adoptable to future changes, i.e if in future we decide to change data source which is not supported by Eloquent then it would be easy to implement the changes
Following structured should be followed:
- app
  - Repository
    - Eloquent
      - BaseRepository.php
      - JobRepository.php  extends BaseRepository implements JobRepositoryInterface
    - EloquentRepositoryInterface.php
    - JobRepositoryInterface extends EloquentRepositoryInterface
## Handling Email Notifications and Logging
* Sending email notifications or logging is part of application flow so this should be resposibility of Controller not the Repository as Repository is responsible for handling business logic.
* Events and Listners should be added for handling Logging or Sending Emails and should be dispatched from Controllers
* Create Email Templates in resources/views/emails folder and use them as body for sending emails
## Proper Naming Convention
1. BookingRepository is receiving **'Job'** model which is not good approach, So I have renamed it to JobRepository.
2. Function names are also not giving proper and clear information e.g BookingRepository recieves Job model and function is named as 'getUsersJobs' where as JobRepository's responsibility should be to work around Job model and so does name should be clear something like **'getJobsOfGivenUser'**
3. The way we have Job::getTranslatorJobs function in same way there should be **Job::getCustomerJobs** function and that should be called in Job Repository
## Better Coding Practice
1. Instead of multiple If Else clauses use **conditional operator** and **gaurded clauses** as much possible to keep the code clean
2. Break bigger functions to use smaller private **functions performing single responsibility**.
3. Changing Translator should be responsibility of Translator Repository 
## Code Refactoring
I have refactored current code's some functions keeping in mind the points shared above. Code in Dev branch could be refactored more but this is acheived in 2-4 hours effort, with intention of exhibiting my knowledge, skills and style of working. 