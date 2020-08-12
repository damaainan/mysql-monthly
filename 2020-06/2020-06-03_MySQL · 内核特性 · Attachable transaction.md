## MySQL · 内核特性 · Attachable transaction


    
## 目的

在学习代码的过程中经常看到attachable transaction，它到底是做什么的，目的是什么呢。这篇文章简单的介绍一下它的作用和用法，以帮助大家理解代码。  

## 简介

Attachable transaction是从5.7引入的一个概念，主要用来对事务类型的系统表访问的接口，从事务的系统表查询得到一致的数据。目前主要是对innodb类型的系统表访问的接口，也只有innodb引擎实现了attachable transaction的支持。Attachable transaction 主要是为访问事务类型的系统表而设计的，它是一个嵌入用户事务的内部事务，当用户事务用到元信息时就需要开启一个attachable transaction去访问数据字典，得到用户表的元信息后，要结束attachable transaction，用户会话要能恢复到用户事务之前的状态。 Attachable transaction 是一个AC-RO-RC-NL (auto-commit, read-only, read-committed, non-locking) 事务。引入Attachable transaction主要有以下几个原因：
1） 如果用户开启的一个事务需要访问系统表获取表的元信息，而访问系统表可能和用户事务指定的隔离级别不一致，这时就需要开启一个独立的访问数据字典的事务，要求访问数据字典事务的隔离级别必须是READ COMMITTED，其隔离级别可能和用户指定的隔离级别不一致。
2） 对数据字典的访问必须是非锁定的。
3） 即时用户事务已经打开和锁定了用户表，在执行SQL语句的在任何时候也应该能对数据字典打开，来查询用户表的各种元信息。  

## 核心数据结构

在每个会话的THD结构里，添加了一个 Attachable_trx *m_attachable_trx;字段，用来指向当前会话的内嵌事务。Attachable_trx 类型定义如下：  

```cpp
  /**  
    Class representing read-only attachable transaction, encapsulates
    knowledge how to backup state of current transaction, start
    read-only attachable transaction in SE, finalize it and then restore
    state of original transaction back. Also serves as a base class for
    read-write attachable transaction implementation.
  */
  class Attachable_trx
  {
  public:
    Attachable_trx(THD *thd);
    virtual ~Attachable_trx();
    virtual bool is_read_only() const { return true; }
  protected:
    /// THD instance.
    THD *m_thd;

    /// Transaction state data.
    Transaction_state m_trx_state;

  private:
    Attachable_trx(const Attachable_trx &);
    Attachable_trx &operator =(const Attachable_trx &);
  };

```


其中最重要的是m_trx_state字段，期存放着attachable transaction的重要信息，就是用它来保存外部用户事务的状态，以便在着attachable transaction结束后能恢复到原来的用户事务状态。其定义如下：  

```cpp
  /** An utility struct for @c Attachable_trx */
  struct Transaction_state
  {
    void backup(THD *thd);
    void restore(THD *thd);

    /// SQL-command.
    enum_sql_command m_sql_command;

    Query_tables_list m_query_tables_list;

    /// Open-tables state.
    Open_tables_backup m_open_tables_state;

    /// SQL_MODE.
    sql_mode_t m_sql_mode;

    /// Transaction isolation level.
    enum_tx_isolation m_tx_isolation;

    /// Ha_data array.
    Ha_data m_ha_data[MAX_HA];

    /// Transaction_ctx instance.
    Transaction_ctx *m_trx;

    /// Transaction read-only state.
    my_bool m_tx_read_only;

    /// THD options.
    ulonglong m_thd_option_bits;

    /// Current transaction instrumentation.
    PSI_transaction_locker *m_transaction_psi;

    /// Server status flags.
    uint m_server_status;
  };

```

## 核心接口API
### 启动一个attachable transaction

主要有这几个函数THD::begin_attachable_transaction()／begin_attachable_ro_transaction()／begin_attachable_rw_transaction(), 启动attachable transaction的过程中主要完成以下功能：
1） 在开始一个attachable_transaction之前，先要保存当前已经开始的用户的正常事务状态。
2） 开始设置一个新的事务所需要的各种状态。
3） 重新设置THD::ha_data。通过重制THD::ha_data值使InnoDB在接下来的操作去创建以下新的事务。
4） 执行对系统表的操作。
5） 当执行到存储引擎层时，InnoDB从传进来的THD指针中发现事务还没开启 （因为THD::ha_data重置了），InnoDB就会新启一个事务。
6） InnoDB通过调用trans_register_ha()通知server它已经创建了一个新事务。
7） InnoDB执行请求的操作，返回给server层。  

```cpp
THD::Attachable_trx::Attachable_trx(THD *thd)
 :m_thd(thd)
{
  m_trx_state.backup(m_thd); //保存当前已经开始的用户的正常事务状态

  ......

  m_thd->reset_n_backup_open_tables_state(&m_trx_state.m_open_tables_state); //保存一些打开表的状态信息，并且重新为新的事物重置表状态

  // 为attachable transaction创建一个新的事物上下文
  m_thd->m_transaction.release(); // it's been backed up.
  m_thd->m_transaction.reset(new Transaction_ctx());

  ......

  for (int i= 0; i < MAX_HA; ++i) 
    m_thd->ha_data[i]= Ha_data(); //重新设置THD::ha_data

  m_thd->tx_isolation= ISO_READ_COMMITTED; // attachable transaction 必须是read committed

  m_thd->tx_read_only= true; ／／ attachable transaction 必须是只读的

  // attachable transaction 必须是 AUTOCOMMIT
  m_thd->variables.option_bits|= OPTION_AUTOCOMMIT;
  m_thd->variables.option_bits&= ~OPTION_NOT_AUTOCOMMIT;
  m_thd->variables.option_bits&= ~OPTION_BEGIN;

  ......
}

```

### 结束一个attachable transaction

THD::end_attachable_transaction()函数。因为attachable transaction事务是一个只读的自提交事务，所以它不需要调用任何事务需要提交火回滚的函数，比如： ha_commit_trans() / ha_rollback_trans() / trans_commit () / trans_rollback ()。所以定义了此函数用来结束当前的attachable transaction。它主要完成以下功能：
1） 调用close_thread_tables()关闭在attachable transaction中打开的表。
2） 调用close_connection()通知引擎层去销毁为attachable transaction创建的事务。
3） InnoDB调用trx_commit_in_memory()去销毁readview等操作。
4） 最后要恢复之前正常的用户事务,包括THD::ha_data的恢复，这个通过调用下面提到的事务状态的backup()/restore()接口。  

```cpp
THD::Attachable_trx::~Attachable_trx()
{
  ......

  close_thread_tables(m_thd); //调用close_thread_tables()关闭在attachable transaction中打开的

  ha_close_connection(m_thd); //调用close_connection()通知引擎层去销毁为attachable transaction创建的事务

  // 恢复之前正常的用户事状态
  m_trx_state.restore(m_thd);

  m_thd->restore_backup_open_tables_state(&m_trx_state.m_open_tables_state);

  ......
}

```

### 事务的保存、恢复接口函数 Transaction_state::backup()／restore()

由于一个会话同时只允许有一个活跃事务，当需要访问内部的事务系统表时，就需要开启一个Attachable transaction事务，这时就要先把外部的主事务状态先保存起来，等内部开启的Attachable transaction事务执行完，再把外部用户执行的事务恢复回来。为了实现这个功能，提供了backup和restore的接口。  

## Handler API的改动

为了让存储层知道server层开启的是一个attachable transaction，handler API新加了一个HA_ATTACHABLE_TRX_COMPATIBLE 标志。设置这个标志的存储引擎类型表示引擎层已经意识到开启了attachable transaction的事务类型。目前InnoDB和MyISAM引擎都能处理这种attachable transaction。但处理方式不同：
1）对InnoDB而言，完全支持attachable transaction事务，能够感知到THD::ha_data
的变化并开启一个attachable transaction事务。在close_connection时结束一个attachable transaction事务，然后恢复用户正常的事务继续处理。
2） 对MyISAM而言，虽然知道server开启了一个attachable transaction事务但也不做任何处理，就是简单但忽律掉 THD::ha_data and close_connection handlerton相关但处理。  


在初始化一个InnoDB表时，设置HA_ATTACHABLE_TRX_COMPATIBLE 标志的代码如下:  

```cpp
ha_innobase::ha_innobase(
/*=====================*/
    handlerton* hton, 
    TABLE_SHARE*    table_arg)
    :handler(hton, table_arg),
    m_prebuilt(),
    m_prebuilt_ptr(&m_prebuilt),
    m_user_thd(),
    m_int_table_flags(HA_REC_NOT_IN_SEQ
    ......
              | HA_ATTACHABLE_TRX_COMPATIBLE
              | HA_CAN_INDEX_VIRTUAL_GENERATED_COLUMN
          ),    
    m_start_of_scan(),
    m_num_write_row(),
        m_mysql_has_locked()
{}

```

