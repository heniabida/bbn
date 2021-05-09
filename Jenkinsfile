properties([pipelineTriggers([githubPush()])])

node{
              
        stage('GitHub Checkout') {
            //checkout([$class: 'GitSCM', branches: [[name: '*/develop']], extensions: [], userRemoteConfigs: [[credentialsId: 'heni_gitea_user', url: 'https://gitea.bbn.so/nabab/bbn-jenkins.git']]]) 
			checkout([$class: 'GitSCM', branches: [[name: '*/develop']], extensions: [], userRemoteConfigs: [[credentialsId: 'heni_gitea_user', url: 'https://gitea.bbn.so/nabab/bbn-jenkins.git']]])
          
            }  
        /**/
        def buildNum = env.BUILD_NUMBER 
        def gitBranch = env.GIT_BRANCH
        def branchName = env.BRANCH_NAME
        def buildTag = env.BUILD_TAG
        def tagName = env.TAG_NAME
        def gitLocalBranch = env.GIT_LOCAL_BRANCH
        def changeId = env.CHANGE_ID
        def changeUrl = env.CHANGE_URL
     	def changeTitle = env.CHANGE_TITLE
      
            /* Récupération du commitID long */
            def commitIdLong = sh returnStdout: true, script: 'git rev-parse HEAD'

            /* Récupération du commitID court */
            def commitId = commitIdLong.take(7)

            print """
            ###################################################################################################################################################
            #                                                       BanchName: $branchName                                                                    #
            #                                                       CommitID: $commitId                                                                       #
            #                                                       JobNumber: $buildNum
            #                                                       Build Tag : $buildTag
            #                                                       Tag Name : $tagName
            #                                                       Git Branch : $gitBranch
            #                                                       Git Local Branch : $gitLocalBranch
            #                                                       Change ID : $changeId
            #                                                       Change URL : $changeUrl
            #                                                       Change Title : $changeTitle
            #                                                       
            ###################################################################################################################################################
            """
            
            stage('SonarQube analysis') {
            
            withSonarQubeEnv('SonarQube_Server') { 
                sh "/opt/sonarscanner/sonarscanner/bin/sonar-scanner -Dsonar.projectKey=bbn-php-develop-gitea -Dsonar.sources=. -Dsonar.host.url=https://sonarqube.bbn.so -Dsonar.login=efd193dd192a388d37a8e617492904a24e998548"
                
            }
            }
        
            stage("Quality Gate") {
                timeout(time: 1, unit: 'HOURS') {
                    // Parameter indicates whether to set pipeline to UNSTABLE if Quality Gate fails
                    // true = set pipeline to UNSTABLE, false = don't
                    waitForQualityGate abortPipeline: false
                }
            }
        stage('Install  Dependencies') {      
                
                sh 'composer install'
        }
        stage('PHPDox generation') {      
                
                sh 'sudo phpdox'
        }
        stage('PHP Loc') {      
                
                sh 'sudo phploc --log-xml=./build/phploc.xml ./src'
        }
        stage('Install PHPCS dependency') {      
                
                sh 'sudo composer require --dev squizlabs/php_codesniffer'
        }

        stage('PHP CS') {      
                try {
           sh 'sudo php vendor/bin/phpcs --report-file=./build/phpcs.log.xml ./src'

            } catch (ex) {
                unstable('Script failed!')
              }
                  }               
        
        stage('PHP MD') {   
          try {
           sh 'sudo phpmd ./src xml cleancode codesize | tee ./build/phpmd.xml > /dev/null'

            } catch (ex) {
                unstable('Script failed!')
              }
                  }
  /*stage('transfet files'){
  ftpPublisher alwaysPublishFromMaster: false, continueOnError: false, failOnError: false, publishers: [[configName: 'FTP_Heni', transfers: [[asciiMode: false, cleanRemote: true, excludes: '', flatten: false, makeEmptyDirs: false, noDefaultExcludes: false, patternSeparator: '[, ]+', remoteDirectory: '/public_html', remoteDirectorySDF: false, removePrefix: 'build/api/html', sourceFiles: 'build/api/html/**']], usePromotionTimestamp: false, useWorkspaceInPromotion: false, verbose: false]]
  }
         
        stage('Update Packagist') { 
          sh 'curl -XPOST -H\'content-type:application/json\' \'https://packagist.org/api/update-package?username=nabab&apiToken=492hld546pc044k40o4g\' -d\'{"repository":{"url":"https://packagist.org/packages/bbn/bbn"}}\''
        }*/
}