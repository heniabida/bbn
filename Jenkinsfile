properties([pipelineTriggers([githubPush()])])

node{
              
        stage('GitHub Checkout') {
            //git branch: $branchName, url: 'https://github.com/heniabida/bbn.git'
                //checkout scm
                checkout([$class: 'GitSCM', branches: [[name: '**/tags/**']], extensions: [], userRemoteConfigs: [[refspec: '+refs/tags/*:refs/remotes/origin/tags/*',credentialsId: 'heni_gitea_user', url: 'https://gitea.bbn.so/nabab/bbn-jenkins.git']]])      
          //sh 'echo $branchName'
                //git branch: $branchName, url: 'https://github.com/heniabida/bbn.git'
                
            }  
        /*--*/
         def buildNum = env.BUILD_NUMBER 
        def githubtoken = "BdWa9m27-XtKyRYrgFdi"
        def gitaccount = "heniabida"
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
            
            /*stage('SonarQube analysis') {
            
            withSonarQubeEnv('SonarQube_Server') { 
                sh "/opt/sonarscanner/sonarscanner/bin/sonar-scanner -Dsonar.projectKey=bbn-php-master -Dsonar.sources=. -Dsonar.host.url=https://sonarqube.bbn.so -Dsonar.login=b53e23d348cfb8999f52fb0f67e2e7365b03ffc8"
                
            }
            }
        
            stage("Quality Gate") {
                timeout(time: 1, unit: 'HOURS') {
                    // Parameter indicates whether to set pipeline to UNSTABLE if Quality Gate fails
                    // true = set pipeline to UNSTABLE, false = don't
                    waitForQualityGate abortPipeline: false
                }
            }*/
        stage('Install  Dependencies') {      
                
                sh 'composer install'
        }
        /*stage('PHP Loc') {      
                
                sh 'phploc --log-xml=./build/phploc.xml ./src'
        }*/
        stage('PHP CS') {      
                
                sh 'phpcs -p --report-file=./build/phpcs.log.xml ./src'
        }
        stage('PHP MD') {      
                
                sh 'phpmd ./src xml cleancode codesize > ./build/phpmd.xml'
        }
        stage('PHP MD') {      
                
                sh 'phpdox'
        }
         
        /*stage('Update Packagist') { 
                sh "bash -x update.sh"
        }*/
}
