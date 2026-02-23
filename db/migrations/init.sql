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

commit;