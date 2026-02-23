start Transaction;

insert into users (username) values ('jude');

select * from users;

insert into user_activity_logs (user_id, action)
values (1, 'Login');

select * from user_activity_logs;


commit;