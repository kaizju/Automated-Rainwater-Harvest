START TRANSACTION;

insert into users (username) values ('jude');

insert into user_activity_logs (user_id, action)
values (LAST_INSERT_ID(), 'put status of the tank');

insert into tank (tankname,location_add,capacity,status_tank) 
values ('Tank 1','Jude Crib','3,542L','Active');

insert into sensors (tank_id,sensor_type,model,unit,is_active) 
values (1,'Water Level','Model 1',1,'Active');





COMMIT;