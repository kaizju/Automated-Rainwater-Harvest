Start Transaction;

create table users (
    user_id int auto_increment primary key,
    username varchar(255) unique not null,
    updated_at timestamp default current_timestamp
    on update current_timestamp
) ENGINE=InnoDB;

create table user_activity_logs (
    activity_id int auto_increment primary key,
user_id int,
action varchar(50) not null,
created_at timestamp default current_timestamp,
foreign key (user_id) references user(user_id)

) ENGINE=InnoDB;

create table tank (
    tank_id int auto_increment primary key,
    tankname varchar(255) unique not null,
    location_add varchar(255) not null,
    capacity varchar(255) not null,
    status_tank varchar(255) unique not null
) ENGINE=InnoDB;

create table sensors (
    sensor_id int auto_increment primary key,
    tank_id int,
    sensor_type varchar(255) not null,
    model varchar(255) not null,
    unit varchar(255) not null,
    is_active varchar(255) not null,
    foreign key (tank_id) references tank (tank_id)
) ENGINE=InnoDB;
commit;