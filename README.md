IMPROVEMENTS on the code

1) Readability and PSR-2 standard compliance specially on conditions and spacings 
2) re-usability of codes if it can be put in config then I used configs for easier maintenance in the future
3) removed deprecated functions and updated it with latest laravel functions like this array_except as it is 
deprecated to future proof the code 
4) removed some unused variables 
5) used null coalescing operator ?? to simplify conditional assignment as this is also one of my style to make codes shorter with same functionality to improve readability
6) on the repository there are a lot of things I have cleaned like the functions that can be re-used I put it on a separate function to reduce code lines and improved the readability of it and re-usability and follow SRP as much as possible Single Responsibility Pattern.
7) By the clean up I did it is now easier to read the codes and follow it and maintain it in the long run. 
8) Used early return pattern as it is my style of coding to avoid unnecessary code blocks that will run even though it is not needed.
9) I also used a centralized empty response as there are a lot of repeating codes that does only one thing which is to return an empty state in the future if there are changes we dont need to modify all lines of codes but to only maintain the single function I created 
10) separated and decomponsed the job end function splitted it to re-usable methods such as calculateSessiontTime(), SendSessionEndedEmail() as there are a lot of code logics that can follow single responsibility  for improvement 
11) Usage of Curl is put into a function in case it will be needed in the future functions 
12) used some data preparer functions as well and modularized logger 
13) Created a notification service and injected the dependency on the bookingrepository constructor and used it on the functions that are using the notifications .
14) on cancel job i did the todo for checking the 24 hrs diff on a separate function 

TEST CASE explanation 

just run php artisan test

and things that it will test

1) willExpireAt method in TeHelper Class it calculating an expiration time based on the difference between two given times: due_time and created_at. You'll need to write tests to ensure it calculates the time correctly in all scenarios following the logic of it 

2) User Repo Test it will assert db transactions 
assertDatabaseHas to verifty if the data is correctly inserted or updated in the DB, factory usage as well , it is going to mock some dependencies like create or Update on models